<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Clientes and Campañas. Additive (nothing existing is touched). Every row carries its Workspace and composite foreign keys
 * make the database refuse to link rows of different Workspaces. Customers, templates and campaigns are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_normalized', 20)->nullable(); // digits only: the identity used to detect duplicates
            $table->string('email', 160)->nullable();
            $table->string('email_normalized', 160)->nullable(); // lower case
            $table->string('source', 16)->default('manual');
            $table->string('notes', 2000)->nullable();
            // Marketing consent: unknown (default, counts as NO) | granted | revoked (opt-out).
            $table->string('marketing_consent', 8)->default('unknown');
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_note', 255)->nullable(); // how/where it was given: the evidence
            $table->string('preferred_channel', 16)->nullable();
            $table->boolean('is_active')->default(true);
            // Set when the personal data was removed on purpose (CustomerRegistry::anonymize). The row stays so conversations,
            // bookings and campaigns that refer to it keep their integrity; it carries no personal data any more.
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            // A contact datum identifies ONE customer per Workspace (null never collides).
            $table->unique(['workspace_id', 'phone_normalized']);
            $table->unique(['workspace_id', 'email_normalized']);
            $table->index(['workspace_id', 'last_name', 'first_name']);
        });

        // The id a customer has on an external channel (the `contact_id` of a conversation). Links to conversations without copying them.
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->string('channel', 16);
            $table->string('external_id', 64);
            $table->timestamps();

            $table->unique(['workspace_id', 'channel', 'external_id']);
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->string('tag', 40);

            $table->unique(['customer_id', 'tag']);
            $table->index(['workspace_id', 'tag']);
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->string('body', 1000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('message_template_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 120);
            $table->string('body', 1000); // copied from the template so editing the template never changes a prepared campaign
            $table->string('channel', 16);
            $table->string('audience', 8)->default('all'); // all (every eligible customer of the filters) | selected (the ids below)
            $table->json('filters')->nullable();
            $table->json('customer_ids')->nullable();
            // draft | prepared | simulated | cancelled. There is no `sent`: no real channel is connected.
            $table->string('status', 12)->default('draft');
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('simulated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            $table->index(['workspace_id', 'status']);
            $table->foreign(['message_template_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('message_templates')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // One row per customer of a prepared campaign: it fixes the audience and records what happened to each one.
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('status', 10); // pending | excluded | simulated (sent / failed are reserved for a real dispatcher)
            $table->string('exclusion_reason', 20)->nullable();
            $table->string('error', 64)->nullable(); // a fixed code, never provider text
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The duplicate-send guard: a customer is in a campaign once.
            $table->unique(['campaign_id', 'customer_id']);
            $table->index(['workspace_id', 'customer_id']);
            $table->foreign(['campaign_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('campaigns')->cascadeOnDelete();
            $table->foreign(['customer_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('customers');
    }
};
