<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inbound webhook endpoint of a Workspace (see App\Webhooks\InboundWebhooks and RECOMENDACIONES.md §79.3): the URL the customer's system calls. The secrets are encrypted at rest and
 * hidden from every serialization: they leave the server once, in the response that creates or rotates them. Written only by App\Webhooks\InboundWebhookService.
 */
class InboundEndpoint extends Model
{
    protected $fillable = ['workspace_id', 'name', 'public_key', 'secret', 'secret_hint', 'previous_secret', 'previous_secret_expires_at', 'secret_rotated_at', 'is_active', 'created_by_user_id'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'previous_secret' => 'encrypted', 'is_active' => 'boolean', 'previous_secret_expires_at' => 'datetime', 'secret_rotated_at' => 'datetime', 'last_received_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InboundEvent::class);
    }

    public function previousSecretIsValid(): bool
    {
        return $this->previous_secret !== null && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isFuture();
    }
}
