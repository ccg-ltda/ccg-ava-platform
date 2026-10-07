<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One contact of a chatbot on a channel (a WhatsApp number). Created and read only through its chatbot, which
 * carries the Workspace; `workspace_id` is never taken from a request.
 */
#[Fillable(['workspace_id', 'channel', 'contact_id', 'contact_name', 'last_message_at'])]
class Conversation extends Model
{
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
