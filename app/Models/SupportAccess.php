<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A grant to read one kind of private data of one Workspace, for a while, with a reason. Written only by App\Privacy\SupportAccess. */
#[Fillable(['workspace_id', 'user_id', 'data_type', 'reason', 'expires_at', 'revoked_at'])]
class SupportAccess extends Model
{
    protected $table = 'support_accesses';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @param  Builder<SupportAccess>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
