<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event on its way to one endpoint: the exact JSON body that is signed and sent, and what happened to it. `status`: pending (not tried yet), sending (an attempt is in
 * progress), failed (it will be retried at `next_attempt_at`), delivered, abandoned (the retries ran out; a person can retry it). It never stores a response body.
 * Written only by App\Webhooks (the publisher, the sender and the service).
 */
class WebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const FAILED = 'failed';

    public const DELIVERED = 'delivered';

    public const ABANDONED = 'abandoned';

    protected $fillable = [
        'public_id', 'webhook_endpoint_id', 'workspace_id', 'event', 'event_id', 'status', 'attempts', 'next_attempt_at', 'last_attempt_at', 'response_status', 'duration_ms', 'error',
        'payload', 'delivered_at',
    ];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'last_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
