<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call of a business tool of the assistant (see the migration for what each column means and what it never holds). Written only by
 * App\Services\AgentToolTrace; never edited by a person and never deleted by the application.
 */
#[Fillable(['workspace_id', 'chatbot_id', 'conversation_id', 'execution_ref', 'tool', 'kind', 'auth', 'status', 'http_status', 'reason', 'idempotency_ref', 'resource_type', 'resource_id', 'replayed', 'result_state', 'notification', 'duration_ms', 'finished_at'])]
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
        return ['replayed' => 'boolean', 'finished_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
