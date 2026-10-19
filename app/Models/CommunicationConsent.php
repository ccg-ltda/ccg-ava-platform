<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One decision of an account about commercial communications (granted or revoked). The latest row is the current choice. */
#[Fillable(['user_id', 'purpose', 'granted', 'source', 'privacy_notice_version', 'recorded_at', 'ip_address'])]
class CommunicationConsent extends Model
{
    protected function casts(): array
    {
        return ['granted' => 'boolean', 'recorded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
