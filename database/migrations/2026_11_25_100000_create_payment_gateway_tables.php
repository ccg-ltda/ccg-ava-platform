<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateways of a Workspace (CardNET, Wompi per Workspace, Azul), PROVIDER-NEUTRAL. Additive: new tables and one nullable column; nothing existing is rewritten.
 *
 * Why the existing tables do not serve: `payment_events` belongs to the platform's own sign-up (it points to `signup_requests`, has no Workspace and its unique key is
 * (provider, environment, transaction, status, timestamp)); `payment_provider_settings` is the single platform Wompi account; `workspace_module_overrides` points to `modules`,
 * and a gateway is not a module; `integrations` holds the credentials but not the payments made with them.
 *
 *  - `payment_links`: the temporary, revocable link a customer opens to pay (only the SHA-256 of the token is stored).
 *  - `payment_transactions`: ONE attempt to collect a payable (an order today) through ONE gateway connection. Identifiers never overlap: `reference` (Ava's own, unique),
 *    `provider_reference` (what Ava sent the provider as the order id), `external_id` (what the provider answered) and `secrets` (encrypted session material).
 *    `active_key` is set only while the transaction is open and is UNIQUE, so a payable can never have two open collections at once, even under concurrency.
 *  - `payment_transaction_events`: what happened to a transaction (browser return, provider notification, server query, job, administrator). `event_key` is UNIQUE per
 *    transaction: the same event delivered twice is processed once (replay protection).
 *  - `workspace_payment_gateway_overrides`: the administrative grant/revoke of a gateway for one Workspace over its plan (same idea as `workspace_module_overrides`).
 *  - `plans.payment_gateways`: the gateways a plan includes. NULL means NONE (closed by default; unlike `plans.channels`, where NULL means every channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('payable_type', 30);
            $table->unsignedBigInteger('payable_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'workspace_id']); // lets a transaction point at its link AND its Workspace together
            $table->index(['workspace_id', 'payable_type', 'payable_id']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('integration_id');
            $table->unsignedBigInteger('payment_link_id')->nullable();
            $table->string('provider', 20);
            $table->string('environment', 12);
            $table->string('payable_type', 30);
            $table->unsignedBigInteger('payable_id');
            $table->string('reference', 40)->unique();
            $table->string('provider_reference', 40)->nullable();
            $table->string('external_id', 80)->nullable();
            $table->string('authorization_code', 40)->nullable();
            $table->string('refund_external_id', 80)->nullable();
            $table->text('secrets')->nullable(); // encrypted array (Laravel `encrypted:array`): session id / session key of the provider
            $table->char('callback_token_hash', 64)->unique(); // the opaque token of the return/cancel/notification URLs of THIS transaction
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24);
            $table->string('failure_code', 40)->nullable();
            $table->string('idempotency_key', 80);
            $table->string('active_key', 120)->nullable()->unique(); // payable_type:payable_id while the transaction is open; NULL once it is closed
            $table->json('metadata')->nullable(); // non-sensitive only
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('check_attempts')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('watch_until')->nullable(); // a transaction Ava closed LOCALLY (expired, superseded, order cancelled) is still asked about until here: the customer may have paid anyway
            $table->timestamps();

            $table->unique(['id', 'workspace_id']);
            // Composite keys: a transaction can only point at a connection and a link of ITS OWN Workspace (the database refuses anything else).
            $table->foreign(['integration_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('integrations')->restrictOnDelete();
            $table->foreign(['payment_link_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('payment_links')->restrictOnDelete();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->unique(['integration_id', 'provider_reference']);
            $table->index(['workspace_id', 'payable_type', 'payable_id']);
            $table->index(['status', 'next_check_at']);
            $table->index(['watch_until', 'next_check_at']);
            $table->index(['provider', 'external_id']);
        });

        Schema::create('payment_transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payment_transaction_id');
            $table->string('kind', 30); // created | redirected | browser_return | notification | query | reconcile | transition | refund | review
            $table->string('source', 20); // browser | provider | server | job | admin
            $table->string('event_key', 120)->nullable();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('outcome', 30)->nullable(); // applied | ignored | duplicate | invalid | mismatch | lookup_failed | rejected
            $table->string('code', 40)->nullable(); // normalized, never provider text
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('detail')->nullable(); // redacted: no card data, no personal data, no secrets
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['payment_transaction_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('payment_transactions')->cascadeOnDelete();
            $table->unique(['payment_transaction_id', 'event_key']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('workspace_payment_gateway_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->string('mode', 10); // grant | revoke
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'gateway']);
        });

        if (! Schema::hasColumn('plans', 'payment_gateways')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->json('payment_gateways')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'payment_gateways')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('payment_gateways');
            });
        }

        Schema::dropIfExists('workspace_payment_gateway_overrides');
        Schema::dropIfExists('payment_transaction_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_links');
    }
};
