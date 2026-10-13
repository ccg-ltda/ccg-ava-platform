<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Servicios y Reservas: the catalog of services, professionals and optional resources, their weekly schedules and
 * exceptions, the minimal data of who books, the appointments and the booking rules of each Workspace. Additive: nothing
 * existing is touched. Every row carries its Workspace and composite foreign keys make it impossible to link rows of
 * different Workspaces (the same pattern as chatbots and conversations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->string('category', 80)->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('specialty', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'name']);
        });

        // Optional: a chair, a room, a machine. A service that lists resources needs one of them free at the same time.
        Schema::create('bookable_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('professional_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('service_id');

            $table->unique(['professional_id', 'service_id']);
            $table->foreign(['professional_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('professionals')->cascadeOnDelete();
            $table->foreign(['service_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('services')->cascadeOnDelete();
        });

        Schema::create('resource_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('service_id');

            $table->unique(['resource_id', 'service_id']);
            $table->foreign(['resource_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('bookable_resources')->cascadeOnDelete();
            $table->foreign(['service_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('services')->cascadeOnDelete();
        });

        // Weekly working intervals of a professional (ISO weekday 1 = Monday .. 7 = Sunday, local time of the Workspace).
        // A break is simply the gap between two intervals of the same day.
        Schema::create('schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('professional_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');

            $table->index(['professional_id', 'weekday']);
            $table->foreign(['professional_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('professionals')->cascadeOnDelete();
        });

        // Days (or hours of a day) nobody can be booked: holidays, vacations, a training. Without a professional it applies to the whole business.
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('professional_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'date_from', 'date_to']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign(['professional_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('professionals')->cascadeOnDelete();
        });

        // Only what is needed to identify and contact whoever books. Not a CRM.
        Schema::create('booking_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 32)->nullable();
            $table->string('email', 160)->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'phone']);
            $table->index(['workspace_id', 'email']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('professional_id')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            // Names as they were when the appointment was made (an external agenda's services have no local row).
            $table->string('service_name', 120);
            $table->string('professional_name', 120)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('blocks_until'); // ends_at + the service's buffer: what the appointment really occupies
            // requested (waiting to be confirmed) | confirmed | completed | no_show | cancelled | failed (the provider refused it)
            $table->string('status', 16);
            $table->string('provider', 16)->default('internal'); // which agenda is the source of truth
            $table->string('external_id', 128)->nullable();
            $table->string('sync_status', 16)->default('none'); // none (internal) | pending | synced | failed
            $table->string('sync_error', 32)->nullable(); // a fixed code, never the provider's text
            $table->string('channel', 16); // admin | web | chat
            $table->string('token_hash', 64)->nullable()->unique(); // the customer's link to manage this appointment
            $table->string('notes', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'starts_at']);
            $table->index(['professional_id', 'starts_at']);
            $table->index(['conversation_id']);
            $table->unique(['provider', 'external_id']);
            $table->foreign(['service_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('services')->restrictOnDelete();
            $table->foreign(['professional_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('professionals')->restrictOnDelete();
            $table->foreign(['resource_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('bookable_resources')->restrictOnDelete();
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('booking_customers')->restrictOnDelete();
            $table->foreign(['conversation_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('conversations')->restrictOnDelete();
        });

        Schema::create('booking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 16)->default('internal'); // internal | booker
            $table->boolean('requires_confirmation')->default(false);
            $table->unsignedInteger('min_notice_minutes')->default(60);
            $table->unsignedSmallInteger('max_advance_days')->default(60);
            $table->unsignedSmallInteger('cancel_notice_hours')->default(2);
            $table->unsignedSmallInteger('slot_step_minutes')->default(15);
            $table->boolean('require_phone')->default(true);
            $table->boolean('require_email')->default(false);
            $table->boolean('public_enabled')->default(false);
            $table->string('public_key', 32)->nullable()->unique();
            $table->timestamps();
        });

        // The database itself refuses two active internal appointments of one professional (or resource) that overlap, even if
        // two requests pass the application checks at the same moment. PostgreSQL only; the other engines rely on the row locks.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_professional_no_overlap EXCLUDE USING gist (professional_id WITH =, tsrange(starts_at, blocks_until) WITH &&) WHERE (professional_id IS NOT NULL AND provider = 'internal' AND status IN ('requested', 'confirmed'))");
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_resource_no_overlap EXCLUDE USING gist (resource_id WITH =, tsrange(starts_at, blocks_until) WITH &&) WHERE (resource_id IS NOT NULL AND provider = 'internal' AND status IN ('requested', 'confirmed'))");
        }
    }

    public function down(): void
    {
        foreach (['booking_settings', 'appointments', 'booking_customers', 'schedule_exceptions', 'schedule_rules', 'resource_service', 'professional_service', 'bookable_resources', 'professionals', 'services'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
