<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call of a business tool of the assistant (see the migration for what each column means and what it never holds). Written only by
 * App\Services\AgentToolTrace; never edited by a person and never deleted by the application.
 */
#[Fillable(['workspace_id', 'chatbot_id', 'conversation_id', 'execution_ref', 'tool', 'kind', 'auth', 'status', 'http_status', 'reason', 'idempotency_ref', 'resource_type', 'resource_id', 'replayed', 'result_state', 'notification', 'duration_ms', 'finished_at', 'source', 'engine', 'execution_id', 'external_ref', 'tool_label', 'trigger_message_id', 'method', 'endpoint', 'request_headers', 'request_query', 'request_body', 'response_headers', 'response_body', 'error_class', 'error_message', 'truncated', 'started_at'])]
class AgentToolCall extends Model
{
    public const UPDATED_AT = null;

    public const STARTED = 'started';

    public const SUCCEEDED = 'succeeded';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    protected function casts(): array
    {
        return ['replayed' => 'boolean', 'truncated' => 'boolean', 'finished_at' => 'datetime', 'started_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
