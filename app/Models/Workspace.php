<?php

namespace App\Models;

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
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = static::normalizeCode($value);
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
