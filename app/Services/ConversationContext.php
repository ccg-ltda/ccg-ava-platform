<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The persisted conversational state the workflow resumes from, kept in `conversations.context` (JSON). It exists because the workflow's own memory is not
 * enough: n8n may run again, the history window is short, and a person may have answered in the middle. Ava stores it; the workflow reads it in every payload and
 * proposes changes in its answer. Nothing here is invented by Ava: every fact carries its SOURCE and its STATUS.
 *
 *   facts.{key}: { value, source: customer|agent|ai, status: confirmed|inferred, at, previous?: [{value, source, status, at}] }
 *   steps:       completed step keys (the process the workflow runs)      next_step: what the workflow says comes next
 *   operations.{key}: { id, at }      what was already DONE (booking, customer created...): the workflow checks it before repeating an action
 *   handoff:     { reason, requested_at, taken_at, returned_at, agent_messages }
 *   summary:     a structured summary SUPPLIED by the workflow for long conversations; it never replaces the stored history
 *
 * Rules: an inference never becomes confirmed by itself and never overwrites a confirmed fact (the contradiction is kept in `conflicts`); a new confirmed value
 * replaces the old one but the old one stays in `previous`; an operation key, once recorded, is never replaced (idempotency). All writes happen under the
 * conversation's row lock, so two processes can not interleave.
 */
class ConversationContext
{
    public const MAX_BYTES = 16384;

    public const SOURCES = ['customer', 'agent', 'ai'];

    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    private static bool $ready = false;

    /** Whether `conversations.context` exists (the migration ran). Without it the context is simply empty and nothing is written: the conversation flows work as before. */
    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasColumn('conversations', 'context');
    }

    public static function forgetReady(): void
    {
        self::$ready = false;
    }

    /** @return array<string, mixed> always a valid structure, even when the stored one is damaged (see `recoverIfDamaged`) */
    public function get(Conversation $conversation): array
    {
        $stored = self::ready() ? $conversation->context : null;

        return $this->valid($stored) ? $stored + $this->empty() : $this->empty();
    }

    /** @return array<string, mixed> */
    public function empty(): array
    {
        return ['version' => 1, 'facts' => [], 'steps' => [], 'next_step' => null, 'operations' => [], 'conflicts' => [], 'handoff' => null, 'summary' => null];
    }

    /**
     * Applies a proposal under the row lock. Facts are `{key: value | {value, confirmed?}}`; `$source` says who provides them. Unknown or malformed parts are ignored,
     * never half-applied: the whole proposal is sanitised first.
     *
     * @param  array<string, mixed>  $proposal  facts, steps, next_step, operations, summary
     * @return array<string, mixed> the stored context
     */
    public function apply(Conversation $conversation, array $proposal, string $source): array
    {
        abort_unless(in_array($source, self::SOURCES, true), 500);
        abort_unless(self::ready(), 409, 'La actualización de la base de datos que guarda los datos de la conversación todavía no se ha aplicado.');

        return DB::transaction(function () use ($conversation, $proposal, $source) {
            $locked = Conversation::whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();
            $context = $this->recoverIfDamaged($locked);

            foreach ((array) ($proposal['facts'] ?? []) as $key => $raw) {
                if (! is_string($key) || ! preg_match(self::KEY, $key)) {
                    continue;
                }

                $value = is_array($raw) ? ($raw['value'] ?? null) : $raw;

                if (! is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }

                // Only a person-provided value is "confirmed". What the workflow proposes is confirmed only when it says the customer stated it.
                $confirmed = $source !== 'ai' || (is_array($raw) && ($raw['confirmed'] ?? false) === true);
                $this->setFact($context, $key, mb_substr(trim((string) $value), 0, 500), $source === 'ai' && $confirmed ? 'customer' : $source, $confirmed ? 'confirmed' : 'inferred');
            }

            foreach ((array) ($proposal['steps'] ?? []) as $step) {
                if (is_string($step) && preg_match(self::KEY, $step) && ! in_array($step, $context['steps'], true) && count($context['steps']) < 60) {
                    $context['steps'][] = $step;
                }
            }

            if (array_key_exists('next_step', $proposal)) {
                $context['next_step'] = is_string($proposal['next_step']) && preg_match(self::KEY, $proposal['next_step']) ? $proposal['next_step'] : null;
            }

            foreach ((array) ($proposal['operations'] ?? []) as $key => $id) {
                // Idempotency: the first record of an operation wins and is never replaced.
                if (is_string($key) && preg_match(self::KEY, $key) && is_scalar($id) && ! isset($context['operations'][$key]) && count($context['operations']) < 40) {
                    $context['operations'][$key] = ['id' => mb_substr((string) $id, 0, 80), 'at' => now()->toIso8601String()];
                }
            }

            if (isset($proposal['summary']) && is_string($proposal['summary'])) {
                $context['summary'] = mb_substr(trim($proposal['summary']), 0, 2000);
            }

            return $this->store($locked, $context);
        });
    }

    /** Records a step of the human handoff (`requested`, `taken`, `returned`); never touches the facts. Called from ConversationControl under its own lock. */
    public function markHandoff(Conversation $locked, string $stage, ?string $reason = null): void
    {
        if (! self::ready()) {
            return;
        }

        $context = $this->recoverIfDamaged($locked);
        $handoff = $context['handoff'] ?? ['reason' => null, 'requested_at' => null, 'taken_at' => null, 'returned_at' => null];

        if ($stage === 'requested') {
            $handoff = ['reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'requested_at' => now()->toIso8601String(), 'taken_at' => null, 'returned_at' => null];
        } elseif ($stage === 'taken') {
            // A person taking it after an earlier cycle ended (returned or resumed) opens a NEW cycle; taking an already open one keeps its first moment.
            $ended = ($handoff['returned_at'] ?? null) !== null || ($handoff['resumed_at'] ?? null) !== null;
            $handoff = $ended ? ['reason' => null, 'requested_at' => null, 'taken_at' => now()->toIso8601String(), 'returned_at' => null] : array_merge($handoff, ['taken_at' => $handoff['taken_at'] ?? now()->toIso8601String()]);
        } elseif ($stage === 'returned') {
            $handoff['returned_at'] = now()->toIso8601String();
            unset($handoff['resumed_at']);
        }

        $context['handoff'] = $handoff;
        $this->store($locked, $context);
    }

    /**
     * The state the workflow needs, for the payload. `resume` is true while the conversation came back from a person and the AI has not answered since.
     *
     * @return array<string, mixed>
     */
    public function forWorkflow(Conversation $conversation): array
    {
        $context = $this->get($conversation);
        $handoff = $context['handoff'];
        $resume = $handoff !== null && ($handoff['returned_at'] ?? null) !== null;

        return [
            'facts' => collect($context['facts'])->map(fn (array $f) => ['value' => $f['value'], 'source' => $f['source'], 'status' => $f['status']])->all(),
            'unknown_facts' => [],
            'steps' => $context['steps'], 'next_step' => $context['next_step'],
            'operations' => collect($context['operations'])->map(fn (array $o) => $o['id'])->all(),
            'conflicts' => $context['conflicts'],
            'summary' => $context['summary'],
            'handoff' => $handoff,
            'resume' => $resume,
        ];
    }

    /**
     * What the agent sees in the conversation panel: the collected data with who provided it and whether it is confirmed, the steps, what was already done and the handoff.
     * Read only here; changes go through `apply`. Same data the workflow gets, never anything else.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(Conversation $conversation): array
    {
        $context = $this->get($conversation);

        return [
            'facts' => collect($context['facts'])->map(fn (array $f, string $key) => ['key' => $key, 'value' => $f['value'], 'source' => $f['source'], 'status' => $f['status'], 'at' => $f['at'] ?? null, 'previous' => count($f['previous'] ?? [])])->values()->all(),
            'steps' => $context['steps'], 'nextStep' => $context['next_step'],
            'operations' => collect($context['operations'])->map(fn (array $o, string $key) => ['key' => $key, 'id' => $o['id'], 'at' => $o['at'] ?? null])->values()->all(),
            'conflicts' => $context['conflicts'], 'summary' => $context['summary'],
            'resuming' => ($context['handoff']['returned_at'] ?? null) !== null,
        ];
    }

    /** True once the AI has answered after the person gave the conversation back: from then on it is a normal conversation again. */
    public function clearResume(Conversation $locked): void
    {
        if (! self::ready()) {
            return;
        }

        $context = $this->get($locked);

        if (($context['handoff']['returned_at'] ?? null) !== null) {
            $context['handoff']['resumed_at'] = now()->toIso8601String();
            $context['handoff']['returned_at'] = null;
            $this->store($locked, $context);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function setFact(array &$context, string $key, string $value, string $source, string $status): void
    {
        $now = now()->toIso8601String();
        $existing = $context['facts'][$key] ?? null;

        if ($existing === null) {
            $context['facts'][$key] = ['value' => $value, 'source' => $source, 'status' => $status, 'at' => $now];

            return;
        }

        if ($existing['value'] === $value) {
            // Same value: confirmation upgrades an inference, an inference never downgrades a confirmation.
            if ($status === 'confirmed' && $existing['status'] !== 'confirmed') {
                $context['facts'][$key] = array_merge($existing, ['status' => 'confirmed', 'source' => $source, 'at' => $now]);
            }

            return;
        }

        if ($status === 'inferred' && $existing['status'] === 'confirmed') {
            // The AI's guess contradicts a confirmed fact: keep the confirmed one and record the disagreement for the workflow to resolve.
            $context['conflicts'][] = ['key' => $key, 'kept' => $existing['value'], 'proposed' => $value, 'proposed_by' => $source, 'at' => $now];
            $context['conflicts'] = array_slice($context['conflicts'], -20);

            return;
        }

        // A newer value replaces the old one; the old one is never lost.
        $previous = $existing['previous'] ?? [];
        $previous[] = ['value' => $existing['value'], 'source' => $existing['source'], 'status' => $existing['status'], 'at' => $existing['at']];
        $context['facts'][$key] = ['value' => $value, 'source' => $source, 'status' => $status, 'at' => $now, 'previous' => array_slice($previous, -5)];
    }

    /** @param  array<string, mixed>  $context */
    private function store(Conversation $locked, array $context): array
    {
        if (strlen((string) json_encode($context)) > self::MAX_BYTES) {
            // Never silently drop facts: the oldest previous values go first, then the proposal is refused as too large.
            foreach ($context['facts'] as &$fact) {
                unset($fact['previous']);
            }
            unset($fact);

            if (strlen((string) json_encode($context)) > self::MAX_BYTES) {
                Log::warning('conversation_context_too_large', ['conversation' => $locked->id]);

                return $this->get($locked);
            }
        }

        $locked->forceFill(['context' => $context])->save();

        return $context;
    }

    /** @return array<string, mixed> the stored context, or an empty one (logged, history untouched) when what is stored is damaged */
    private function recoverIfDamaged(Conversation $locked): array
    {
        if ($locked->context === null) {
            return $this->empty();
        }

        if (! $this->valid($locked->context)) {
            Log::error('conversation_context_damaged', ['conversation' => $locked->id]);

            return $this->empty() + ['recovered' => true];
        }

        return $locked->context + $this->empty();
    }

    private function valid(mixed $stored): bool
    {
        return is_array($stored) && ($stored['version'] ?? null) === 1 && is_array($stored['facts'] ?? null) && is_array($stored['steps'] ?? null) && is_array($stored['operations'] ?? null);
    }
}
