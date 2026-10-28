<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One redacted outbound API call (see App\Observability\ApiCallRecorder). Never holds a secret: redaction happens before it is stored. */
class ApiCallLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['truncated' => 'boolean', 'created_at' => 'datetime'];
    }
}
