<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A document of the knowledge base of one Workspace (see the migration `create_knowledge_base_tables` and KnowledgeBase). Written only by KnowledgeBase. */
class KnowledgeDocument extends Model
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const ERROR = 'error';

    /** What KnowledgeBase::store() writes; status, counters and errors change only through its own queries, never through request data. */
    protected $fillable = ['workspace_id', 'title', 'original_name', 'extension', 'mime', 'size_bytes', 'sha256', 'storage_path', 'status', 'uploaded_by'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }

    public function chatbots(): BelongsToMany
    {
        return $this->belongsToMany(Chatbot::class, 'knowledge_document_chatbot', 'document_id', 'chatbot_id')->withTimestamps();
    }
}
