<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One redacted outbound API call (see App\Observability\ApiCallRecorder). Never holds a secret: redaction happens before it is stored. */
class ApiCallLog extends Model
{
    public const UPDATED_AT = null;

    /** Written only by ApiCallRecorder (and the tests): an explicit list, never `$guarded = []`. */
    protected $fillable = [
        'workspace_id', 'integration', 'operation', 'method', 'host', 'path', 'status_code', 'outcome', 'duration_ms', 'correlation_id', 'error_class', 'error_message',
        'request_headers', 'request_body', 'response_headers', 'response_body', 'truncated', 'resource_type', 'resource_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['truncated' => 'boolean', 'created_at' => 'datetime'];
    }
}
