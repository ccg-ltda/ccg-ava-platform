<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform's Meta app (single row). Secrets are encrypted at rest and never serialized: the interface only learns whether each is set and its last characters. */
class MetaPlatformSetting extends Model
{
    protected $guarded = [];

    protected $hidden = ['app_secret', 'instagram_app_secret', 'webhook_verify_token'];

    protected function casts(): array
    {
        return ['app_secret' => 'encrypted', 'instagram_app_secret' => 'encrypted', 'webhook_verify_token' => 'encrypted', 'validated_at' => 'datetime'];
    }
}
