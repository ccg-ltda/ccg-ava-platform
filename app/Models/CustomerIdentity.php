<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The id a customer has on an external channel (the `contact_id` of its conversations). One per Workspace + channel + id. */
#[Fillable(['workspace_id', 'channel', 'external_id'])]
class CustomerIdentity extends Model
{
    /** WhatsApp ids are phone numbers (digits), emails-like ids are case-insensitive, the rest is kept as given. */
    public static function normalize(string $channel, string $externalId): string
    {
        $value = trim($externalId);

        return $channel === 'whatsapp' ? (string) preg_replace('/\D+/', '', $value) : $value;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
