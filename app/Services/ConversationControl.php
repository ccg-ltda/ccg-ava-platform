<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Exceptions\ConversationStateException;
use App\Models\Conversation;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes WHO ANSWERS a conversation (`conversations.handling`): the AI, or a human agent.
 *
 *   ai --requestHuman--> pending --take--> human --returnToAi--> ai
 *   ai --take--> human            human/pending/ai --resolve--> resolved --(the contact writes)--> ai
 *
 * Every change runs under a row lock on the conversation, so two agents cannot take it at once and a change never
 * overlaps an automatic reply being authorised (see `ConversationService::authorizeReply`), and every change bumps
 * `handling_version`. Changes made by a person are audited. Whether the user MAY do something is decided by the caller
 * through `$manage` (`manage-conversations`); whether the STATE allows it is decided here.
 */
class ConversationControl
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConversationAgents $agents,
    ) {}

    /** The automation (or a rule) asks for a person. Nothing happens when a person already has it or was already asked. */
    public function requestHuman(Conversation $conversation, ?string $reason = null): Conversation
    {
        return $this->change($conversation, function (Conversation $locked) use ($reason) {
            if (! in_array($locked->handling, [Conversation::PENDING, Conversation::HUMAN], true)) {
                $this->move($locked, Conversation::PENDING, null, ['handoff_reason' => filled($reason) ? mb_substr(trim($reason), 0, 255) : null]);
            }

            return null;
        });
    }

    /** An agent takes the conversation (from the AI or from the pending queue). */
    public function take(Conversation $conversation, User $agent): Conversation
    {
        return $this->change($conversation, function (Conversation $locked) use ($agent) {
            if ($locked->handling === Conversation::HUMAN) {
                throw new ConversationStateException($locked->assigned_user_id === $agent->id ? 'Ya tienes esta conversación.' : 'Otro agente ya tiene esta conversación.');
            }

            $this->assertOpen($locked);
            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::HUMAN, $agent->id);

            return ['taken', $before];
        });
    }

    /** A manager hands the conversation to a given agent (also taking it from another one). */
    public function assign(Conversation $conversation, User $assignee): Conversation
    {
        return $this->change($conversation, function (Conversation $locked) use ($assignee) {
            $this->assertOpen($locked);

            if (! $this->agents->isAgent($assignee, $locked->chatbot->workspace)) {
                throw new ConversationStateException('Ese usuario no puede atender conversaciones en este Workspace.');
            }

            if ($locked->handling === Conversation::HUMAN && $locked->assigned_user_id === $assignee->id) {
                throw new ConversationStateException('Esa conversación ya está asignada a ese agente.');
            }

            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::HUMAN, $assignee->id);

            return ['assigned', $before];
        });
    }

    /** The assigned agent (or a manager) gives the conversation back to the AI. */
    public function returnToAi(Conversation $conversation, User $actor, bool $manage): Conversation
    {
        return $this->change($conversation, function (Conversation $locked) use ($actor, $manage) {
            if (! in_array($locked->handling, [Conversation::HUMAN, Conversation::PENDING], true)) {
                throw new ConversationStateException('La conversación ya la atiende la IA o está resuelta.');
            }

            $this->assertOperator($locked, $actor, $manage);
            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::AI, null);

            return ['returned', $before];
        });
    }

    /** The assigned agent (or a manager) closes the case. The AI stays quiet until the contact writes again. */
    public function resolve(Conversation $conversation, User $actor, bool $manage): Conversation
    {
        return $this->change($conversation, function (Conversation $locked) use ($actor, $manage) {
            $this->assertOpen($locked);
            $this->assertOperator($locked, $actor, $manage);
            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::RESOLVED, $locked->assigned_user_id, ['resolved_at' => now()]);

            return ['resolved', $before];
        });
    }

    /** The contact wrote to a resolved conversation: it opens again with the AI. Called under the caller's lock. */
    public function reopen(Conversation $locked): void
    {
        if ($locked->handling === Conversation::RESOLVED) {
            $this->move($locked, Conversation::AI, null);
        }
    }

    /** Whether the user may use the conversation as its agent: it is theirs, or they manage conversations. */
    public function isOperator(Conversation $conversation, User $user, bool $manage): bool
    {
        return $manage || ($conversation->handling === Conversation::HUMAN && $conversation->assigned_user_id === $user->id);
    }

    /**
     * Locks the conversation, runs `$work` on the fresh row and, when it returns `[action, before]`, audits it.
     *
     * @param  Closure(Conversation): (array{0: string, 1: array<string, ?string>}|null)  $work
     */
    private function change(Conversation $conversation, Closure $work): Conversation
    {
        return DB::transaction(function () use ($conversation, $work) {
            $locked = Conversation::with('chatbot.workspace', 'assignedUser')->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $audited = $work($locked);

            if ($audited) {
                $this->record($audited[0], $locked, $audited[1]);
            }

            return $locked->refresh();
        });
    }

    private function assertOpen(Conversation $conversation): void
    {
        if ($conversation->handling === Conversation::RESOLVED) {
            throw new ConversationStateException('La conversación está resuelta: se reabre cuando el contacto vuelva a escribir.');
        }
    }

    private function assertOperator(Conversation $conversation, User $actor, bool $manage): void
    {
        if ($conversation->handling === Conversation::HUMAN && ! $this->isOperator($conversation, $actor, $manage)) {
            throw new ConversationStateException('Esta conversación la atiende otro agente.');
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function move(Conversation $conversation, string $handling, ?int $assignee, array $extra = []): void
    {
        $conversation->forceFill([
            'handling' => $handling,
            'assigned_user_id' => $assignee,
            'handling_version' => $conversation->handling_version + 1,
            // A change of control clears what belonged to the previous one, unless the new state sets it again.
            'handoff_reason' => null,
            'handoff_requested_at' => $handling === Conversation::PENDING ? now() : null,
            'resolved_at' => null,
            ...$extra,
        ])->save();
    }

    /** @return array<string, ?string> */
    private function snapshot(Conversation $conversation): array
    {
        return ['Atención' => Conversation::LABELS[$conversation->handling], 'Agente' => $conversation->assignedUser?->name];
    }

    /** @param  array<string, ?string>  $before */
    private function record(string $action, Conversation $conversation, array $before): void
    {
        $conversation->unsetRelation('assignedUser')->load('assignedUser');
        $changes = [];

        foreach ($this->snapshot($conversation) as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[] = AuditLogger::change($field, $before[$field], $value);
            }
        }

        $this->audit->record($action, 'conversation', $conversation->id, $conversation->contact_name ?: $conversation->contact_id, $changes, $conversation->chatbot->workspace);
    }
}
