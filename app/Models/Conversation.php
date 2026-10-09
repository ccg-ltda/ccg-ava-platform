<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One contact of a chatbot on a channel (a WhatsApp number, a web visitor). Created and read only through its chatbot,
 * which carries the Workspace; `workspace_id` is never taken from a request.
 *
 * `handling` says who answers it: `ai` (the automation), `pending` (a human was requested and nobody has taken it),
 * `human` (an agent has it) or `resolved`. Only `ai` lets the automation reply. It changes only through
 * App\Services\ConversationControl, which also bumps `handling_version`.
 */
#[Fillable(['workspace_id', 'channel', 'contact_id', 'contact_name', 'demo_key', 'last_message_at'])]
class Conversation extends Model
{
    public const AI = 'ai';

    public const PENDING = 'pending';

    public const HUMAN = 'human';

    public const RESOLVED = 'resolved';

    public const HANDLING = [self::AI, self::PENDING, self::HUMAN, self::RESOLVED];

    public const LABELS = [
        self::AI => 'IA atendiendo',
        self::PENDING => 'Pendiente de agente',
        self::HUMAN => 'En atención',
        self::RESOLVED => 'Resuelta',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'handoff_requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'handling_version' => 'integer',
        ];
    }

    /** A conversation of the demo environment (see App\Services\DemoConversations): nothing of it reaches a real channel. */
    public function isDemo(): bool
    {
        return $this->demo_key !== null;
    }

    /** Whether the automation may answer right now. */
    public function aiMayReply(): bool
    {
        return $this->handling === self::AI;
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
