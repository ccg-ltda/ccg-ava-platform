<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message of a conversation, as reported by the automation. Created only through its conversation. */
#[Fillable(['workspace_id', 'direction', 'type', 'body', 'media_mime', 'external_id', 'status', 'sent_at'])]
class Message extends Model
{
    public const DIRECTIONS = ['in', 'out'];

    public const TYPES = ['text', 'audio', 'image', 'video', 'document', 'sticker', 'location', 'other'];

    public const STATUSES = ['sent', 'delivered', 'read', 'failed'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
