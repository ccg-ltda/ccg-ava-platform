<?php

namespace App\Services;

use App\Audit\AuditLogger;
use App\Exceptions\ConversationStateException;
use App\Models\Conversation;
use App\Models\User;
use App\Webhooks\Webhooks;
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
        private readonly ModuleAccess $modules,
        private readonly SupportChannel $support,
        private readonly ConversationContext $context,
    ) {}

    /**
     * Whether the AI can have this conversation at all: the Workspace's plan includes Asistentes. A plan with Conversaciones
     * only is a consultation plan: people can read, take, assign and resolve, but nothing can hand a conversation to the AI.
     */
    public function aiAvailable(Conversation $conversation): bool
    {
        return $this->modules->allows($conversation->chatbot->workspace, 'assistants');
    }

    /** The automation (or a rule) asks for a person. Nothing happens when a person already has it or was already asked. */
    public function requestHuman(Conversation $conversation, ?string $reason = null): Conversation
    {
        $moved = false;

        $result = $this->change($conversation, function (Conversation $locked) use ($reason, &$moved) {
            if (in_array($locked->handling, [Conversation::PENDING, Conversation::HUMAN], true)) {
                return null;
            }

            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::PENDING, null, ['handoff_reason' => filled($reason) ? mb_substr(trim($reason), 0, 255) : null]);
            $this->context->markHandoff($locked, 'requested', $reason);
            $moved = true;

            // Only the first request is an event; an automatic one (the assistant) is attributed to its source, never to a person.
            return ['requested', $before];
        });

        // CCG's support chat: the team is alerted once per request (the id of the alert carries the state version).
        if ($moved) {
            $this->support->notify('human_requested', $result);
            $this->announceHandoff($result);
        }

        return $result;
    }

    /**
     * D-FALLO: the AI failed for good on a message and nobody else has answered. Only a conversation the AI still has, exactly as it was when the
     * failed execution started (`$expectedVersion`), moves to `pending`: one a person already holds (pending or human) is left alone, a resolved one
     * is NOT reopened (the contact's next message does that) and one whose control changed since is not touched. Returns whether it moved, so
     * the caller raises the alert and sends the approved notice exactly once.
     */
    public function handOverAfterFailure(Conversation $conversation, int $expectedVersion, string $code): bool
    {
        $moved = false;

        $result = $this->change($conversation, function (Conversation $locked) use ($expectedVersion, $code, &$moved) {
            if ($locked->handling !== Conversation::AI || $locked->handling_version !== $expectedVersion) {
                return null;
            }

            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::PENDING, null, ['handoff_reason' => "La IA no pudo responder ({$code}). Atiende a esta persona."]);
            $this->context->markHandoff($locked, 'requested', "La IA no pudo responder ({$code}).");
            $moved = true;

            return ['requested', $before];
        });

        if ($moved) {
            $this->support->notify('human_requested', $result);
            $this->announceHandoff($result);
        }

        return $moved;
    }

    /**
     * The persisted notification of a real transition to a person (one per transition: the key carries the state version, so a repeated request or a retry can not add a second one).
     * A message that arrives while it is already pending is NOT a transition and never gets here.
     */
    private function announceHandoff(Conversation $conversation): void
    {
        $conversation->loadMissing('chatbot.workspace');

        app(Notifications::class)->notify(
            $conversation->chatbot->workspace, 'conversation_pending', 'Una conversación necesita tu atención', null, '/conversations?c='.$conversation->id, 'view-conversations',
            null, 'conversation', $conversation->id, 'conv:'.$conversation->id.':handoff:v'.$conversation->handling_version,
        );
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
            $this->context->markHandoff($locked, 'taken');

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
            $this->context->markHandoff($locked, 'taken');

            return ['assigned', $before];
        });
    }

    /** The assigned agent (or a manager) gives the conversation back to the AI. */
    public function returnToAi(Conversation $conversation, User $actor, bool $manage): Conversation
    {
        return $this->change($conversation, function (Conversation $locked) use ($actor, $manage) {
            if (! $this->aiAvailable($locked)) {
                throw new ConversationStateException('El plan de este Workspace no incluye Asistentes: no hay IA a la que devolver la conversación.');
            }

            if (! in_array($locked->handling, [Conversation::HUMAN, Conversation::PENDING], true)) {
                throw new ConversationStateException('La conversación ya la atiende la IA o está resuelta.');
            }

            $this->assertOperator($locked, $actor, $manage);
            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::AI, null);
            // The state the workflow resumes from stays exactly as it was (facts the customer and the agent gave included); this only marks that a person handed it back.
            $this->context->markHandoff($locked, 'returned');

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
            // Resolving a client's SUPPORT request ends its session for good (who, when and why are kept, and the person's next message opens a new conversation).
            $support = app(SupportDesk::class)->isRequest($locked);
            $this->move($locked, Conversation::RESOLVED, $locked->assigned_user_id, ['resolved_at' => now()] + ($support ? $this->closing($locked, 'agent', $actor) : []));

            return [$support ? 'closed_agent' : 'resolved', $before];
        });
    }

    /**
     * Ends a support session (the client, an agent through `resolve`, or the clock). Under the row lock the messages take: `$allowed` is asked again on the fresh row (the
     * inactivity re-check), a session that is already closed is left alone, and `$after` runs inside the same transaction (the system line). Returns whether THIS call closed it.
     *
     * @param  Closure(Conversation): bool  $allowed
     * @param  Closure(Conversation): void  $after
     */
    public function closeSupportSession(Conversation $conversation, string $reason, ?User $by, Closure $allowed, Closure $after): bool
    {
        $closed = false;

        $this->change($conversation, function (Conversation $locked) use ($reason, $by, $allowed, $after, &$closed) {
            if ($locked->closed_at !== null || ! $allowed($locked)) {
                return null;
            }

            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::RESOLVED, $locked->assigned_user_id, ['resolved_at' => now()] + $this->closing($locked, $reason, $by));
            $after($locked);
            $closed = true;

            return ['closed_'.$reason, $before];
        });

        return $closed;
    }

    /**
     * Resolves an ORDINARY conversation that went quiet (the contact's next message reopens it with the AI, as with any resolution). `$allowed` is asked again on the fresh row under
     * the lock, so activity or an AI execution that got there first keeps it open; an already resolved one is left alone (idempotent). Returns whether THIS call closed it.
     *
     * @param  Closure(Conversation): bool  $allowed
     */
    public function resolveForInactivity(Conversation $conversation, Closure $allowed): bool
    {
        $closed = false;

        $this->change($conversation, function (Conversation $locked) use ($allowed, &$closed) {
            if ($locked->handling === Conversation::RESOLVED || $locked->closed_at !== null || ! $allowed($locked)) {
                return null;
            }

            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::RESOLVED, $locked->assigned_user_id, ['resolved_at' => now()]);
            $closed = true;

            return ['closed_inactivity', $before];
        });

        return $closed;
    }

    /** @return array<string, mixed> the columns of a closed session, including the archived key that frees the person's own one */
    private function closing(Conversation $locked, string $reason, ?User $by): array
    {
        return ['closed_at' => now(), 'closed_by_user_id' => $by?->id, 'closed_reason' => $reason, 'contact_id' => SupportSessions::archivedKey($locked)];
    }

    /** The contact wrote to a resolved conversation: it opens again with the AI. Called under the caller's lock. */
    public function reopen(Conversation $locked): void
    {
        if ($locked->handling === Conversation::RESOLVED) {
            $before = $this->snapshot($locked);
            $this->move($locked, Conversation::AI, null);
            $this->record('reopened', $locked, $before);
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

        $label = $conversation->contact_name ?: $conversation->contact_id;
        $workspace = $conversation->chatbot->workspace;

        // What an automatic process can do (the assistant asks for a person, a contact reopens a case) is recorded as its own
        // source's event; what only a person can do needs that person (nothing is recorded without a signed-in user).
        match ($action) {
            'requested' => $this->audit->recordForActor($action, 'conversation', $conversation->id, $label, $changes, $workspace, 'Asistente (automático)'),
            'reopened' => $this->audit->recordForActor($action, 'conversation', $conversation->id, $label, $changes, $workspace, 'Contacto (mensaje nuevo)'),
            // The clock closed it: always the system's event, never the person whose request happened to run the check (their poll is not their action).
            'closed_inactivity' => $this->audit->system($action, 'conversation', $conversation->id, $label, $changes, $workspace, 'Sistema (inactividad)'),
            default => $this->audit->record($action, 'conversation', $conversation->id, $label, $changes, $workspace),
        };

        // Outbound webhooks (§78): every change of who handles a conversation passes through here, once, whoever caused it. Ids and states only.
        Webhooks::publish($workspace, 'conversation.handling_changed', [
            'conversation_id' => $conversation->id, 'action' => $action, 'handling' => $conversation->handling, 'handling_version' => $conversation->handling_version,
        ]);
    }
}
