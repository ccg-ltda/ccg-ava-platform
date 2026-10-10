<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message of a conversation, as reported by the automation. Created only through its conversation. */
#[Fillable(['workspace_id', 'direction', 'sender', 'sender_user_id', 'type', 'body', 'media_mime', 'external_id', 'status', 'failure_reason', 'simulated', 'sent_at'])]
class Message extends Model
{
    public const DIRECTIONS = ['in', 'out'];

    public const TYPES = ['text', 'audio', 'image', 'video', 'document', 'sticker', 'location', 'other'];

    /** Who wrote it: the contact, the automation or a human agent. */
    public const SENDERS = ['contact', 'ai', 'agent'];

    /** Delivery states the automation may report. */
    public const STATUSES = ['sent', 'delivered', 'read', 'failed'];

    /** A message Ava has accepted (an agent's, or the AI's) but not yet handed to the channel (it is not a state n8n reports). */
    public const PENDING = 'pending';

    /** Handed to the channel, but the channel's answer was lost: it may or may not have been delivered. Never resent automatically. */
    public const UNCONFIRMED = 'unconfirmed';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'simulated' => 'boolean'];
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
