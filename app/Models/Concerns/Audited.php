<?php

namespace App\Models\Concerns;

use App\Audit\AuditRecorder;
use App\Audit\Masked;
use App\Models\Workspace;
use Closure;

/**
 * Makes a model record its creation, changes and deletion in the audit log (App\Audit\AuditLogger).
 *
 * The model declares: `auditResource()` (a key of config/audit.php), `auditLabel()` (the human name of the record),
 * `auditFields()` (attribute => field label; only these are ever compared or shown) and, when it has them,
 * `auditMasked()` for values that must stay hidden. `auditWorkspace()` tells which Workspace the record belongs to
 * (null = the Workspace of the request).
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn ($model) => app(AuditRecorder::class)->created($model));
        static::updated(fn ($model) => app(AuditRecorder::class)->updated($model));
        static::deleted(fn ($model) => app(AuditRecorder::class)->deleted($model));
    }

    abstract public function auditResource(): string;

    abstract public function auditLabel(): string;

    /** @return array<string, string> attribute => field label */
    abstract public function auditFields(): array;

    /** @return array<string, string> attribute => field label of the values that are never shown (credentials) */
    public function auditMasked(): array
    {
        return [];
    }

    public function auditWorkspace(): ?Workspace
    {
        return null;
    }

    /**
     * Field label => display value (or Masked), read through `$get` so the same description serves the old and the
     * new state of the record.
     *
     * @return array<string, string|Masked|null>
     */
    public function auditSnapshot(Closure $get): array
    {
        return $this->auditBaseSnapshot($get);
    }

    /**
     * What `auditFields()` and `auditMasked()` declare; a model that overrides `auditSnapshot()` starts from it.
     *
     * @return array<string, string|Masked|null>
     */
    protected function auditBaseSnapshot(Closure $get): array
    {
        $values = [];

        foreach ($this->auditFields() as $attribute => $label) {
            $values[$label] = $this->auditDisplay($get($attribute));
        }

        foreach ($this->auditMasked() as $attribute => $label) {
            $values[$label] = Masked::of($get($attribute));
        }

        return $values;
    }

    protected function auditDisplay(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'Sí' : 'No',
            $value === null || $value === '' => null,
            default => (string) $value,
        };
    }
}
