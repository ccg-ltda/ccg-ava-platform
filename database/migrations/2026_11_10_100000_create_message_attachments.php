<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Files attached to a message (today: the screenshots, videos and PDFs a client administrator sends to support). Additive: a new table, nothing existing changes. The file
 * lives on the private disk under a server-generated path (`storage_path`, never built from the name the person gave); only the row, which the application checks against the
 * conversation and the Workspace on every read, gives access to it. The composite foreign key makes the database itself refuse an attachment whose message belongs to
 * another conversation or Workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10); // image | video | pdf
            $table->string('mime', 100); // detected on the server from the content, not the one the browser claimed
            $table->string('original_name', 150); // for display only: sanitized, never used as a path
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_path', 255);
            $table->timestamps();

            $table->index(['message_id']);
            $table->foreign(['message_id', 'conversation_id', 'workspace_id'])->references(['id', 'conversation_id', 'workspace_id'])->on('messages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
