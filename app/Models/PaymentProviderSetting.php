<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Operational credentials of a payment provider for ONE environment. Secrets are encrypted at rest with the application key (Laravel `encrypted` cast) and are
 * hidden from every serialization; the interface only ever receives "is it set" and the last characters of a secret.
 */
class PaymentProviderSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['private_key', 'events_secret', 'integrity_secret'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'validated_at' => 'datetime',
            'private_key' => 'encrypted', 'events_secret' => 'encrypted', 'integrity_secret' => 'encrypted',
        ];
    }
}
