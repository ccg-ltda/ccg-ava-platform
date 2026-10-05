<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns an Eloquent event of an audited model (see Concerns\Audited) into an audit event with only the relevant
 * differences: what was created, what changed (before/after per field) or what a deleted record had.
 */
class AuditRecorder
{
    public const HIDDEN = 'Valor oculto';

    public function __construct(private readonly AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $changes = collect($model->auditSnapshot(fn ($key) => $model->getAttribute($key)))
            ->map(fn ($value, $field) => $this->entry($field, null, $value))
            ->filter(fn ($entry) => $entry['after'] !== null)
            ->values()->all();

        $this->logger->record('created', $model->auditResource(), $model->getKey(), $model->auditLabel(), $changes, $model->auditWorkspace());
    }

    public function updated(Model $model): void
    {
        $before = $model->auditSnapshot(fn ($key) => $model->getOriginal($key));
        $after = $model->auditSnapshot(fn ($key) => $model->getAttribute($key));
        $changes = [];

        foreach (array_keys($before + $after) as $field) {
            if (! $this->same($before[$field] ?? null, $after[$field] ?? null)) {
                $changes[] = $this->entry($field, $before[$field] ?? null, $after[$field] ?? null);
            }
        }

        $this->logger->record('updated', $model->auditResource(), $model->getKey(), $model->auditLabel(), $changes, $model->auditWorkspace());
    }

    public function deleted(Model $model): void
    {
        $changes = collect($model->auditSnapshot(fn ($key) => $model->getOriginal($key)))
            ->map(fn ($value, $field) => $this->entry($field, $value, null))
            ->filter(fn ($entry) => $entry['before'] !== null)
            ->values()->all();

        $this->logger->record('deleted', $model->auditResource(), $model->getKey(), $model->auditLabel(), $changes, $model->auditWorkspace());
    }

    private function same(string|Masked|null $before, string|Masked|null $after): bool
    {
        if ($before instanceof Masked || $after instanceof Masked) {
            return $before instanceof Masked && $after instanceof Masked && $before->fingerprint === $after->fingerprint;
        }

        return $before === $after;
    }

    /** @return array{field: string, before: ?string, after: ?string} */
    private function entry(string $field, string|Masked|null $before, string|Masked|null $after): array
    {
        // A masked value shows its harmless text, or "Valor oculto" when it has none; an absent value stays null.
        $text = fn (string|Masked|null $value): ?string => $value instanceof Masked ? ($value->display ?? self::HIDDEN) : $value;

        $entry = AuditLogger::change($field, $text($before), $text($after));

        // Both sides hidden: say that the hidden value was replaced, not that it stayed the same.
        if ($before instanceof Masked && $after instanceof Masked && $entry['before'] === $entry['after']) {
            $entry['after'] .= ' (modificado)';
        }

        return $entry;
    }
}
