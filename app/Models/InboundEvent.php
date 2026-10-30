<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ACCEPTED event received on an inbound endpoint: its id, type, size and time (never the body). Written only by App\Webhooks\InboundWebhooks. */
class InboundEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['inbound_endpoint_id', 'workspace_id', 'event_id', 'event_type', 'bytes'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(InboundEndpoint::class, 'inbound_endpoint_id');
    }
}
