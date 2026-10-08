<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Services\ChannelAppearanceService;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The look of the button / widget of one channel of a chatbot. `workspace_id` always comes from the chatbot (never
 * from a request) and the database refuses a chatbot of another Workspace.
 */
#[Fillable(['workspace_id', 'channel', 'settings'])]
class ChatbotChannelAppearance extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function auditResource(): string
    {
        return 'chatbot_channel_appearance';
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
        return [];
    }

    /** One row per setting, with the label the interface uses, so the history shows exactly what changed. */
    public function auditSnapshot(Closure $get): array
    {
        $settings = $get('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;
        $labels = app(ChannelAppearanceService::class)->labels();
        $values = [];

        foreach ($labels as $key => $label) {
            $value = $settings[$key] ?? null;
            $values[$label] = match (true) {
                is_bool($value) => $value ? 'Sí' : 'No',
                $value === null || $value === '' => null,
                default => (string) $value,
            };
        }

        return $values;
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }
}
