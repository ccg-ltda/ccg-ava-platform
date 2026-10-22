<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The global HTML template of the platform's emails (see App\Services\EmailTemplates). Data, never code. */
#[Fillable(['key', 'html', 'updated_by'])]
class EmailTemplate extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
