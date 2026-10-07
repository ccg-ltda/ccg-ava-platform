<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A channel of a chatbot. `workspace_id` always comes from the chatbot (never from a request) and the database
 * only accepts an `integration_id` that belongs to that same Workspace.
 */
#[Fillable(['workspace_id', 'channel', 'integration_id', 'is_active', 'public_key'])]
class ChatbotChannel extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditResource(): string
    {
        return 'chatbot_channel';
    }

    public function auditLabel(): string
    {
        return (config("chatbots.channels.{$this->channel}.label") ?? $this->channel).' · '.$this->chatbot->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->chatbot->workspace;
    }

    public function auditFields(): array
    {
        return ['is_active' => 'Activo'];
    }

    public function auditSnapshot(Closure $get): array
    {
        return $this->auditBaseSnapshot($get) + [
            'Integración' => $get('integration_id') ? Integration::whereKey($get('integration_id'))->value('name') : null,
        ];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
