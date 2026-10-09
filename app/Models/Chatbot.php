<?php

namespace App\Models;

use App\Audit\Masked;
use App\Models\Concerns\Audited;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A chatbot owned by one Workspace. It is created and read only through `$workspace->chatbots()`. */
#[Fillable(['name', 'description', 'instructions', 'is_active', 'avatar_path'])]
#[Hidden(['agent_token_hash'])]
class Chatbot extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_demo' => 'boolean', 'agent_token_created_at' => 'datetime', 'profile_saved_at' => 'datetime', 'agent_last_seen_at' => 'datetime'];
    }

    public function auditResource(): string
    {
        return 'chatbot';
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function auditFields(): array
    {
        return ['name' => 'Nombre', 'description' => 'Descripción', 'instructions' => 'Instrucciones', 'is_active' => 'Activo'];
    }

    public function auditSnapshot(Closure $get): array
    {
        return $this->auditBaseSnapshot($get) + [
            'Avatar' => $get('avatar_path') ? 'Cargado' : null,
            // Only the fingerprint of the stored hash is compared: generating or revoking a token is audited, its value never.
            'Token de acceso del agente' => Masked::of($get('agent_token_hash'), 'Configurado'),
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ChatbotChannel::class);
    }

    public function appearances(): HasMany
    {
        return $this->hasMany(ChatbotChannelAppearance::class);
    }
}
