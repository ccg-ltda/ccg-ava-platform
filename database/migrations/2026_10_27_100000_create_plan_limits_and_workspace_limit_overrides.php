<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Usage limits, in two levels that never overwrite each other. Additive and reversible; run it deliberately in the authorized environment, after
 * `2026_10_24` to `2026_10_26` (`make dev-sync` locally, `make prod-migrate`). Nothing is created by it: no plan and no Workspace receives a limit.
 *
 *  - plan_limits: the STANDARD limit a plan sells, one row per (plan, metric). No row = the plan sets no limit for that metric (nothing is enforced; it is
 *    never read as a number). Today the only enforced metric is `users` (members of a Workspace; see App\Services\WorkspaceLimits).
 *  - workspace_limit_overrides: the PARTICULAR limit of ONE Workspace, authorized by the platform administration with a reason and its author. `value` null
 *    means "explicitly unlimited for this Workspace". It wins over the plan and touches no other Workspace; deleting the row returns the Workspace to the
 *    plan's standard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->unsignedInteger('value');
            $table->timestamps();

            $table->unique(['plan_id', 'metric']);
        });

        Schema::create('workspace_limit_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 40);
            $table->unsignedInteger('value')->nullable(); // null = unlimited for this Workspace
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_limit_overrides');
        Schema::dropIfExists('plan_limits');
    }
};
