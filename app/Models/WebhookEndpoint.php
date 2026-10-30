<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination a Workspace registered for its outbound webhooks (see App\Webhooks and RECOMENDACIONES.md §78). The secrets are encrypted at rest and hidden from
 * every serialization: they leave the server only once, in the response that creates or rotates them. Written only by App\Webhooks\WebhookService.
 */
class WebhookEndpoint extends Model
{
    protected $fillable = [
        'workspace_id', 'name', 'url', 'secret', 'secret_hint', 'previous_secret', 'previous_secret_expires_at', 'secret_rotated_at', 'events', 'is_active', 'disabled_reason',
        'consecutive_failures', 'last_success_at', 'last_failure_at', 'created_by_user_id',
    ];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted', 'previous_secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean',
            'previous_secret_expires_at' => 'datetime', 'secret_rotated_at' => 'datetime', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** The previous secret still signs a second header during the grace period after a rotation. */
    public function previousSecretIsValid(): bool
    {
        return $this->previous_secret !== null && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->isFuture();
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }
}
