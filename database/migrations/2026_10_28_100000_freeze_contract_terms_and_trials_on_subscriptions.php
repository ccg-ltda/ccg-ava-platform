<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Frozen commercial conditions and manual free trials, on the subscription that already exists. Additive and reversible; run it deliberately in the
 * authorized environment AFTER `2026_10_24` (subscriptions) and `2026_10_25` (sign-up), and before relying on either feature. It writes NO data: every
 * existing subscription keeps its row exactly as it is, with `terms` null.
 *
 *  - subscriptions.terms (json) + terms_source + terms_captured_at: the CONDITIONS accepted when the plan was contracted (plan, price, currency, period,
 *    modules and limits). Editing the plan afterwards never changes them. `terms` null means the conditions are HISTORICAL AND INCOMPLETE: nothing is
 *    invented for them and they are shown as pending review (an administrator may freeze the current ones explicitly).
 *  - subscriptions.trial_*: a free trial granted by hand to ONE Workspace from the administration (start, end, reason, who granted it). Status `trialing`.
 *  - signup_requests.limits_snapshot (json): the limits of the plan at the moment the customer accepted, so provisioning freezes what was accepted and not
 *    what the plan says later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('terms')->nullable();
            $table->string('terms_source', 20)->nullable(); // signup | manual | trial | frozen_current
            $table->timestamp('terms_captured_at')->nullable();
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('trial_reason', 500)->nullable();
            $table->foreignId('trial_granted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('signup_requests', function (Blueprint $table) {
            $table->json('limits_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signup_requests', function (Blueprint $table) {
            $table->dropColumn('limits_snapshot');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_granted_by');
            $table->dropColumn(['terms', 'terms_source', 'terms_captured_at', 'trial_starts_at', 'trial_ends_at', 'trial_reason']);
        });
    }
};
