<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A passage of text extracted from a knowledge document: the unit that is searched. */
class KnowledgeChunk extends Model
{
    public $timestamps = false;

    /** The chunks are inserted by KnowledgeBase with the query builder; this list only documents the columns and keeps mass assignment closed otherwise. */
    protected $fillable = ['workspace_id', 'document_id', 'position', 'content'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
