<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical activity that does not depend on WHO runs the API call. `agent_tool_calls` already traces the tools Ava serves; this adds what is needed to show
 * the request and the response of every step and to let an automation engine (n8n today, Lava Studio later) REPORT the calls it makes on its own:
 *  - `source`: `ava` (written by Ava while serving a tool) or `reported` (sent by the engine through POST /api/agent/activity/steps). Nothing is invented for
 *    a reported step: a field the engine did not send stays null and the screen says «no reportado».
 *  - `engine`: `n8n`, `lava` or null (unknown). `execution_id` is the engine's own run id; `execution_ref` keeps Ava's correlation id.
 *  - `external_ref`: idempotency key of a reported step, unique per Workspace, so an engine retry updates the same row instead of creating another.
 *  - `trigger_message_id`: the inbound message that started the run (composite key: it must belong to the same conversation and Workspace).
 *  - `method`, `endpoint`, `request_*`, `response_*`, `error_*`: REDACTED by App\Observability\ApiRedactor before they are stored (tokens, cookies, passwords and keys
 *    never reach this table). Bodies are capped (`truncated` says so).
 * Additive: every new column is nullable, the existing rows and the code that writes them keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_tool_calls', function (Blueprint $table) {
            $table->string('source', 8)->default('ava');
            $table->string('engine', 12)->nullable();
            $table->string('execution_id', 64)->nullable();
            $table->string('external_ref', 120)->nullable();
            $table->string('tool_label', 80)->nullable();
            $table->unsignedBigInteger('trigger_message_id')->nullable();
            $table->string('method', 8)->nullable();
            $table->string('endpoint', 512)->nullable();
            $table->text('request_headers')->nullable();
            $table->text('request_query')->nullable();
            $table->text('request_body')->nullable();
            $table->text('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error_class', 120)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestamp('started_at')->nullable();

            $table->unique(['workspace_id', 'external_ref']);
            $table->index(['conversation_id', 'execution_id']);

            // SQLite (the test database) can neither add nor drop a composite foreign key by name; PostgreSQL, the real database, enforces it.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->foreign(['trigger_message_id', 'conversation_id', 'workspace_id'], 'agent_tool_calls_trigger_message_fk')
                    ->references(['id', 'conversation_id', 'workspace_id'])->on('messages')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool_calls', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign('agent_tool_calls_trigger_message_fk');
            }

            $table->dropUnique(['workspace_id', 'external_ref']);
            $table->dropIndex(['conversation_id', 'execution_id']);
            $table->dropColumn(['source', 'engine', 'execution_id', 'external_ref', 'tool_label', 'trigger_message_id', 'method', 'endpoint', 'request_headers', 'request_query', 'request_body', 'response_headers', 'response_body', 'error_class', 'error_message', 'truncated', 'started_at']);
        });
    }
};
