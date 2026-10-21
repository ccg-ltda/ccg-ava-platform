<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Public sign-up and the commercial data it needs. Additive and reversible: nothing existing is rewritten, every new column is nullable or has a safe
 * default (a plan is NOT offered publicly until CCG marks it so and gives it a solution, a price, a currency and a period), and no Workspace, user or
 * subscription is created by this migration. Run it deliberately in the authorized environment (`make dev-sync` locally, `make prod-migrate`).
 *
 *  - plans: the solution it belongs to (a module category), its price in minor units, currency and billing period, whether it is offered in the public
 *    sign-up and whether it is sold by quote instead of an immediate purchase. Limits are not modelled: they are an open commercial decision.
 *  - organizations: tax id (NIT) and contact phone of the company that signs up.
 *  - signup_verifications: one-time codes that prove an email can receive mail (only a keyed hash of the code is stored).
 *  - signup_requests: the pending contract of a company that is signing up. It holds the data of the wizard and a snapshot of the price chosen; the
 *    Organization, Workspace, user and subscription exist only after a verified payment (SignupService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('module_category_id')->nullable()->after('description')->constrained('module_categories')->restrictOnDelete();
            $table->unsignedBigInteger('price_cents')->nullable()->after('module_category_id'); // minor units (cents); null = no price configured
            $table->string('currency', 3)->nullable()->after('price_cents');
            $table->string('billing_period', 10)->nullable()->after('currency'); // monthly | yearly
            $table->boolean('is_public')->default(false)->after('billing_period'); // offered in the public sign-up
            $table->boolean('requires_quote')->default(false)->after('is_public'); // sold through a quote, never an immediate checkout
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tax_id', 20)->nullable()->unique()->after('name');
            $table->string('phone', 30)->nullable()->after('tax_id');
        });

        Schema::create('signup_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('sends')->default(1);
            $table->timestamp('last_sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['email', 'created_at']);
        });

        Schema::create('signup_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('email');
            $table->timestamp('email_verified_at');
            $table->string('admin_name')->nullable();
            $table->string('password')->nullable(); // hashed; the account is created only after the payment
            $table->string('company_name')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('workspace_code', 50)->nullable();
            $table->string('workspace_name')->nullable();
            $table->string('solution_key', 20)->nullable(); // a module category id, or 'custom'
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            // The conditions the customer saw: later changes to the plan never alter what is charged or checked on this request.
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('billing_period', 10)->nullable();
            // draft | awaiting_payment | quote_requested | completed | cancelled | expired
            $table->string('status', 20)->default('draft');
            $table->json('accepted_documents')->nullable(); // {document key: version} accepted at the summary
            $table->json('modules_snapshot')->nullable(); // keys of the plan's modules when the contract was confirmed (to notice later changes)
            $table->timestamp('accepted_at')->nullable();
            $table->string('payment_reference', 120)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['email', 'status']);
            $table->index(['workspace_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_requests');
        Schema::dropIfExists('signup_verifications');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['tax_id']);
            $table->dropColumn(['tax_id', 'phone']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('module_category_id');
            $table->dropColumn(['price_cents', 'currency', 'billing_period', 'is_public', 'requires_quote']);
        });
    }
};
