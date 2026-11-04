<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting data that does not depend on which system owns the agenda or which engine runs the conversation.
 *
 *  - `booking_records`: one row per reservation made in ANY system (`source`: booker | lava | ava), keyed by the id that system gave it
 *    (`unique(workspace_id, source, external_id)`: re-sending or re-reading a reservation updates the same row). A reservation Ava itself created in Booker
 *    keeps `appointment_id` so the consolidated report counts it once. Only what the source supplies is stored; nothing is invented.
 *  - `surveys` (definition: questions and scale), `survey_dispatches` (a survey sent to a customer) and `survey_responses` (what came back), each keyed by an
 *    idempotency key (`external_ref`, unique per Workspace) so an engine retry never duplicates a row. Answers are JSON per question: a survey is not always a number.
 * Every row carries `workspace_id` and composite foreign keys keep related rows inside the same Workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12); // booker | lava | ava
            $table->string('external_id', 128);
            $table->string('engine', 12)->nullable(); // who reported it: n8n | lava | null (read by Ava itself)
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('location_ref', 64)->nullable(); // the source's own location id/name when it is not an Ava location
            $table->string('service_name', 160)->nullable();
            $table->string('professional_name', 160)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('appointment_id')->nullable(); // set when the reservation is also an Ava appointment (counted once)
            $table->string('status', 12); // booked | confirmed | completed | no_show | cancelled
            $table->string('origin', 40)->nullable(); // where the reservation was made (web, phone, Ava chat...) as the source says
            $table->timestamp('booked_at')->nullable(); // created in the source
            $table->timestamp('starts_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'source', 'external_id']);
            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'starts_at']);
            $table->index(['workspace_id', 'status', 'starts_at']);
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->restrictOnDelete();
            $table->foreign(['appointment_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('appointments')->restrictOnDelete();
        });

        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64); // stable id the engine uses
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('type', 16)->default('satisfaction'); // satisfaction | nps | custom
            $table->string('status', 10)->default('active'); // active | archived
            $table->unsignedSmallInteger('scale_min')->nullable();
            $table->unsignedSmallInteger('scale_max')->nullable();
            $table->json('questions')->nullable(); // [{key, label, type: rating|text|choice|boolean|number, options?}]
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
            $table->unique(['id', 'workspace_id']);
        });

        Schema::create('survey_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('survey_id');
            $table->string('external_ref', 120);
            $table->string('engine', 12)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('booking_record_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('channel', 20)->nullable();
            $table->string('status', 10)->default('sent'); // sent | failed | answered
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'external_ref']);
            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'sent_at']);
            $table->foreign(['survey_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('surveys')->restrictOnDelete();
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->restrictOnDelete();
            $table->foreign(['conversation_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['booking_record_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('booking_records')->restrictOnDelete();
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('survey_id');
            $table->unsignedBigInteger('dispatch_id')->nullable();
            $table->string('external_ref', 120);
            $table->string('engine', 12)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('booking_record_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->decimal('rating', 6, 2)->nullable(); // the overall score on the survey's scale
            $table->text('comment')->nullable();
            $table->json('answers')->nullable(); // {question key: answer}
            $table->boolean('is_complete')->default(true); // false = the person stopped before answering everything
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'external_ref']);
            $table->index(['workspace_id', 'answered_at']);
            $table->index(['survey_id', 'answered_at']);
            $table->foreign(['survey_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('surveys')->restrictOnDelete();
            $table->foreign(['dispatch_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('survey_dispatches')->restrictOnDelete();
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->restrictOnDelete();
            $table->foreign(['conversation_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('conversations')->restrictOnDelete();
            $table->foreign(['booking_record_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('booking_records')->restrictOnDelete();
            $table->foreign(['location_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_dispatches');
        Schema::dropIfExists('surveys');
        Schema::dropIfExists('booking_records');
    }
};
