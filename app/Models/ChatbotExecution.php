<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of a chatbot's workflow for one inbound message (see App\Services\ChatbotExecutions, the only writer). Always
 * created from a conversation of the chatbot's own Workspace, which the composite foreign keys also enforce;
 * `workspace_id` is never taken from a request. It stores identifiers, fixed codes and times, never message text.
 */
#[Fillable(['correlation_id', 'workspace_id', 'chatbot_id', 'conversation_id', 'message_id', 'workflow_key', 'n8n_workflow_id', 'workflow_version', 'handling_version', 'status', 'attempts', 'outcome_code', 'control_result', 'reply_message_id', 'delivery', 'started_at', 'finished_at'])]
class ChatbotExecution extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const DISCARDED = 'discarded';

    public const FAILED = 'failed';

    /** Delivery of the reply to the channel: stored, handed over (outcome not recorded), accepted, refused for sure, or unknown. */
    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_SENDING = 'sending';

    public const DELIVERY_ACCEPTED = 'accepted';

    public const DELIVERY_FAILED = 'failed';

    public const DELIVERY_UNCERTAIN = 'uncertain';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'handling_version' => 'integer', 'attempts' => 'integer'];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_message_id');
    }
}
