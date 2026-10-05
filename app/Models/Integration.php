<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external connection owned by one Workspace. `secrets` is encrypted at rest and hidden from serialization:
 * it must only be read by the integration type that talks to the service, never sent to the frontend.
 */
#[Fillable([
    'name', 'provider', 'description', 'type', 'is_active', 'config', 'secrets',
    'last_tested_at', 'last_test_ok', 'last_test_status', 'last_test_message',
])]
#[Hidden(['secrets'])]
class Integration extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
            'secrets' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
