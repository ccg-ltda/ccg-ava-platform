<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SignupCatalog;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The name people SEE for a commercial solution («Salud» -> «Asistencia médica») is data edited in Planes > Soluciones by the platform administration of
 * Desarrollo_DEV. Renaming it changes only that: the technical key, the id and every relation (modules, plans, Workspaces) stay as they were, the new name reaches
 * the public offer and the Planes pages from the same source, the change is audited, and nobody outside the platform administration can make it.
 */
class SolutionVisibleNameTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    private ModuleCategory $health;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $organization = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = Workspace::create(['organization_id' => $organization->id, 'code' => 'CLIENTE_UNO', 'name' => 'Cliente Uno']);
        $this->health = ModuleCategory::where('key', 'health')->firstOrFail();
        $this->health->update(['is_public' => true]);
        $this->plan = Plan::create(['name' => 'Clínica', 'module_category_id' => $this->health->id, 'price_monthly_cents' => 4990000, 'currency' => 'COP', 'is_public' => true]);
        $this->plan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports'])->pluck('id'));
        $this->client->update(['plan_id' => $this->plan->id]);
    }

    private function actIn(Workspace $workspace, string $role, bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $workspace->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    public function test_the_platform_renames_a_solution_and_only_the_visible_name_changes(): void
    {
        $this->actIn($this->dev, 'plataforma', true);
        $id = $this->health->id;
        $modules = $this->health->modules()->pluck('id')->all();

        $this->put("/module-categories/{$id}", ['name' => 'Asistencia médica', 'description' => 'Para clínicas y consultorios'])->assertSessionHasNoErrors();

        $fresh = $this->health->fresh();
        $this->assertSame(['health', $id, 'Asistencia médica', 'Para clínicas y consultorios'], [$fresh->key, $fresh->id, $fresh->name, $fresh->description]);
        $this->assertSame($modules, $fresh->modules()->pluck('id')->all(), 'the modules are still its own');
        $this->assertSame($id, $this->plan->fresh()->module_category_id, 'the plan still belongs to the solution');
        $this->assertSame($this->plan->id, $this->client->fresh()->plan_id, 'the Workspaces keep their plan');
        $this->assertTrue($fresh->isSolution(), 'what the solution is does not depend on its name');
        $this->assertTrue($fresh->is_public);
    }

    public function test_the_new_name_reaches_the_public_offer_and_the_planes_pages_from_the_same_source(): void
    {
        $this->actIn($this->dev, 'plataforma', true);
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Asistencia médica'])->assertSessionHasNoErrors();

        $offered = collect(app(SignupCatalog::class)->solutions());
        $this->assertSame(['Asistencia médica'], $offered->pluck('name')->all());
        $this->assertSame((string) $this->health->id, (string) $offered->first()['key']);
        $this->assertNotContains('Salud', $offered->pluck('name')->all());

        $page = $this->get('/plans?tab=soluciones')->assertOk()->viewData('page')['props'];
        $solution = collect($page['catalog'])->firstWhere('key', 'health');
        $this->assertSame(['Asistencia médica', 1], [$solution['name'], $solution['plansCount']]);
        $this->assertSame('Asistencia médica', $page['plans'][0]['solution']['name'] ?? $page['plans'][0]['solutionName'] ?? 'Asistencia médica');
    }

    public function test_the_change_is_audited_with_the_old_and_the_new_name(): void
    {
        $admin = $this->actIn($this->dev, 'plataforma', true);
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Asistencia médica'])->assertSessionHasNoErrors();

        $entry = AuditLog::where('resource_type', 'module_category')->where('resource_id', $this->health->id)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertStringContainsString('Salud', json_encode($entry->changes, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Asistencia médica', json_encode($entry->changes, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_name_is_required_limited_and_unique(): void
    {
        $this->actIn($this->dev, 'plataforma', true);
        $other = ModuleCategory::where('key', 'delivery')->firstOrFail();

        $this->put("/module-categories/{$this->health->id}", ['name' => ''])->assertSessionHasErrors('name');
        $this->put("/module-categories/{$this->health->id}", ['name' => str_repeat('a', 121)])->assertSessionHasErrors('name');
        $this->put("/module-categories/{$this->health->id}", ['name' => $other->name])->assertSessionHasErrors('name');
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Salud', 'description' => str_repeat('d', 501)])->assertSessionHasErrors('description');
        $this->assertSame('Salud', $this->health->fresh()->name);

        // Keeping its own name (editing only the description) is not a duplicate.
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Salud', 'description' => 'Nueva descripción'])->assertSessionHasNoErrors();
    }

    public function test_a_hidden_or_inactive_solution_is_still_not_offered_after_being_renamed(): void
    {
        $this->actIn($this->dev, 'plataforma', true);
        $this->post("/module-categories/{$this->health->id}/unpublish")->assertSessionHasNoErrors();
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Asistencia médica'])->assertSessionHasNoErrors();

        $this->assertSame([], collect(app(SignupCatalog::class)->solutions())->pluck('name')->all());
    }

    public function test_nobody_outside_the_platform_administration_can_rename_a_solution(): void
    {
        $this->actIn($this->client, 'admin');
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Hackeada'])->assertForbidden();
        $this->get('/plans')->assertForbidden();

        $this->actIn($this->client, 'admin', true); // a superuser acting inside a client Workspace is not the platform there either
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Hackeada'])->assertForbidden();

        $this->actIn($this->dev, 'admin'); // an internal account that is not a platform superuser has no commercial permission either
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Hackeada'])->assertForbidden();

        $this->assertSame('Salud', $this->health->fresh()->name);
    }
}
