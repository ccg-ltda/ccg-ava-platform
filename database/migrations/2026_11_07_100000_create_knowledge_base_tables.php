<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledge base of the assistants: documents of a Workspace, the text extracted from them in chunks, which assistants may use each document and a trace of what was
 * retrieved. Everything carries the Workspace in a composite key, so the database itself refuses to link a document, a chunk or an assistant of another Workspace.
 *
 *  - `knowledge_documents`: the uploaded file (kept on the default disk under `workspaces/{id}/knowledge/…`, never served publicly), its checksum (the same file is not stored twice in
 *    a Workspace), and the processing `status`: pending → processing → ready | error (`error_code` is a fixed code: unsupported type, unreadable, empty, extractor unavailable).
 *  - `knowledge_chunks`: the extracted text split into passages; this is what is searched (PostgreSQL full-text search, `spanish`). Deleting a document deletes its chunks.
 *  - `knowledge_document_chatbot`: the assistants allowed to use a document (a document no assistant is linked to is never retrieved).
 *  - `knowledge_retrievals`: which documents/chunks answered a query of an assistant (ids and counts only; neither the question nor the text is stored).
 * Additive: nothing existing changes. down() drops the four tables (the files on the disk are not touched by a migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('original_name', 255);
            $table->string('extension', 10);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('storage_path', 255)->nullable();
            $table->string('status', 12)->default('pending'); // pending | processing | ready | error
            $table->string('error_code', 40)->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->unsignedInteger('text_chars')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'sha256']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('position');
            $table->text('content');

            $table->unique(['document_id', 'position']);
            $table->index('workspace_id');
            $table->foreign(['document_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('knowledge_documents')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX knowledge_chunks_content_fts ON knowledge_chunks USING GIN (to_tsvector('spanish', content))");
        }

        Schema::create('knowledge_document_chatbot', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('chatbot_id');
            $table->timestamps();

            $table->unique(['document_id', 'chatbot_id']);
            $table->foreign(['document_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('knowledge_documents')->cascadeOnDelete();
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
        });

        Schema::create('knowledge_retrievals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('chatbot_id');
            $table->unsignedSmallInteger('query_chars');
            $table->json('sources'); // [{document_id, chunk_id}]
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'chatbot_id', 'created_at']);
            $table->foreign(['chatbot_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('chatbots')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_retrievals');
        Schema::dropIfExists('knowledge_document_chatbot');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
    }
};
