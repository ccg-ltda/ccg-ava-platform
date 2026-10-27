<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A passage of text extracted from a knowledge document: the unit that is searched. */
class KnowledgeChunk extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
