<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account accepted one version of one legal document. Never edited or removed. */
#[Fillable(['user_id', 'document', 'version', 'accepted_at', 'ip_address'])]
class LegalAcceptance extends Model
{
    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
