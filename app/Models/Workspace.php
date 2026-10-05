<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'code', 'name', 'is_active'])]
class Workspace extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** The administrative Workspace of Ava Platform (see config/workspace.php `admin_code`). */
    public function isAdministrative(): bool
    {
        return $this->code === static::normalizeCode((string) config('workspace.admin_code'));
    }

    /** Workspaces whose name, code or Organization contains the text (case-insensitive, wildcards are literal). */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        // "!" is the escape character so user input cannot act as a LIKE wildcard.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("lower(workspaces.name) like ? escape '!'", [$like])
            ->orWhereRaw("lower(workspaces.code) like ? escape '!'", [$like])
            ->orWhereHas('organization', fn (Builder $o) => $o->whereRaw("lower(organizations.name) like ? escape '!'", [$like])));
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = static::normalizeCode($value);
    }

    public function auditResource(): string
    {
        return 'workspace';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    public function auditFields(): array
    {
        return ['code' => 'Código', 'name' => 'Nombre', 'is_active' => 'Activo'];
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this;
    }

    public function auditSnapshot(Closure $get): array
    {
        return $this->auditBaseSnapshot($get) + ['Organización' => Organization::whereKey($get('organization_id'))->value('name')];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(WorkspaceSetting::class);
    }

    /** The saved preferences, or the defaults of config/workspace.php for a Workspace that never saved them. */
    public function settingsOrDefault(): WorkspaceSetting
    {
        if (! $this->relationLoaded('settings') || $this->getRelation('settings') === null) {
            $this->setRelation('settings', $this->settings()->first() ?? new WorkspaceSetting(WorkspaceSetting::defaults()));
        }

        return $this->getRelation('settings');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /**
     * Workspaces that are enabled and whose Organization is enabled.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('workspaces.is_active', true)
            ->whereHas('organization', fn (Builder $q) => $q->where('is_active', true));
    }
}
