<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The administrative Workspace and the Workspace selector: who may look beyond their own Workspace, and how. */
class WorkspaceSelectorTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $role = 'admin', bool $superuser = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    private function workspace(string $code, string $name, ?Organization $organization = null, bool $active = true): Workspace
    {
        return Workspace::create([
            'organization_id' => ($organization ?? Organization::first() ?? Organization::create(['name' => 'Test Org']))->id,
            'code' => $code,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    // --- the administrative Workspace --------------------------------------------------------------------------

    public function test_the_administrative_workspace_is_identified_by_its_code_whatever_the_case(): void
    {
        $this->assertSame('DESARROLLO_DEV', config('workspace.admin_code'));

        $dev = $this->workspace('DESARROLLO_DEV', 'Desarrollo');
        $other = $this->workspace('DESARROLLO_DSA', 'Desarrollo DSA');
        $this->assertTrue($dev->isAdministrative());
        $this->assertFalse($other->isAdministrative());

        config(['workspace.admin_code' => 'Desarrollo_DSA']);
        $this->assertTrue($other->isAdministrative());
        $this->assertFalse($dev->isAdministrative());
    }

    public function test_the_administrative_workspace_cannot_be_deactivated_renamed_by_code_or_lose_its_organization(): void
    {
        config(['workspace.admin_code' => 'ADMIN_WS']);
        $this->actAs('admin', superuser: true);
        $admin = $this->workspace('ADMIN_WS', 'Admin', Organization::create(['name' => 'Org interna']));

        $this->post("/workspaces/{$admin->id}/deactivate")->assertSessionHasErrors('status');
        $this->put("/workspaces/{$admin->id}", ['code' => 'OTHER_CODE', 'name' => 'Admin'])->assertSessionHasErrors('code');
        $this->post("/organizations/{$admin->organization_id}/deactivate")->assertSessionHasErrors('status');
        $this->put("/workspaces/{$admin->id}", ['code' => 'ADMIN_WS', 'name' => 'Admin renombrado'])->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertSame('ADMIN_WS', $admin->code);
        $this->assertSame('Admin renombrado', $admin->name);
        $this->assertTrue($admin->organization->is_active);
    }

    // --- searching to view ----------------------------------------------------------------------------------------

    public function test_only_a_superuser_in_the_administrative_workspace_may_search_to_view(): void
    {
        $this->actAs('admin');
        $this->getJson('/workspaces/search?purpose=view')->assertForbidden();

        $this->actAs('admin', superuser: true);
        $this->getJson('/workspaces/search?purpose=view')->assertForbidden();

        config(['workspace.admin_code' => 'TEST_WS']);
        $this->getJson('/workspaces/search?purpose=view')->assertOk();
    }

    public function test_guests_and_unknown_purposes_are_refused(): void
    {
        $this->getJson('/workspaces/search?purpose=view')->assertUnauthorized();

        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);
        $this->getJson('/workspaces/search')->assertJsonValidationErrors('purpose');
        $this->getJson('/workspaces/search?purpose=everything')->assertJsonValidationErrors('purpose');
    }

    public function test_the_search_finds_by_name_code_or_organization_and_matches_literally(): void
    {
        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);
        $acme = Organization::create(['name' => 'Acme Corp']);
        $this->workspace('DESARROLLO_DEV', 'Desarrollo');
        $this->workspace('DESARROLLO_DSA', 'Desarrollo DSA');
        $this->workspace('CLIENTE_X', 'DSA Cliente', $acme);

        $codes = fn (string $query) => collect($this->getJson('/workspaces/search?purpose=view&q='.urlencode($query))->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['CLIENTE_X', 'DESARROLLO_DSA'], $codes('DSA'));
        $this->assertSame(['CLIENTE_X'], $codes('acme'));
        $this->assertSame(['DESARROLLO_DEV', 'DESARROLLO_DSA'], $codes('desarrollo_d'));
        $this->assertSame([], $codes('%'));
        $this->assertSame([], $codes('DESARROLLO%DEV'));
        $this->assertSame([], $codes('no-existe'));
    }

    public function test_the_search_is_capped_and_tells_when_there_are_more(): void
    {
        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);

        foreach (range(1, 14) as $i) {
            $this->workspace(sprintf('MASIVO_%02d', $i), sprintf('Masivo %02d', $i));
        }

        $response = $this->getJson('/workspaces/search?purpose=view&q=masivo')->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertTrue($response->json('hasMore'));
        $this->assertSame('MASIVO_01', $response->json('data.0.code'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertFalse($this->getJson('/workspaces/search?purpose=view&q=masivo_14')->json('hasMore'));
        // Without text it still answers with one page, never the whole list.
        $this->assertCount(10, $this->getJson('/workspaces/search?purpose=view')->json('data'));
    }

    public function test_each_result_says_what_it_is(): void
    {
        $this->actAs('admin', superuser: true);
        config(['workspace.admin_code' => 'TEST_WS']);
        $this->workspace('DORMIDO', 'Dormido', null, active: false);

        $found = collect($this->getJson('/workspaces/search?purpose=view')->json('data'))->keyBy('code');

        $this->assertTrue($found['TEST_WS']['isAdministrative']);
        $this->assertSame('Test Org', $found['TEST_WS']['organization']);
        $this->assertFalse($found['DORMIDO']['isActive']);
        $this->assertNull($found['DORMIDO']['roles']);
    }

    // --- searching to assign -----------------------------------------------------------------------------------------

    public function test_assigning_needs_manage_users_and_offers_only_what_the_user_administers(): void
    {
        $this->actAs('cliente');
        $this->getJson('/workspaces/search?purpose=assign')->assertForbidden();

        $manager = $this->actAs('admin');
        $mine = $this->workspace('MIO_A', 'Mío A');
        $mine->users()->attach($manager->id, ['role' => 'admin']);
        $this->workspace('AJENO', 'Ajeno');
        $closed = $this->workspace('MIO_CERRADO', 'Mío cerrado', null, active: false);
        $closed->users()->attach($manager->id, ['role' => 'admin']);

        $response = $this->getJson('/workspaces/search?purpose=assign')->assertOk();

        $this->assertSame(['MIO_A', 'TEST_WS'], collect($response->json('data'))->pluck('code')->sort()->values()->all());
        $this->assertContains('admin', $response->json('data.0.roles'));
        $this->assertSame([], collect($this->getJson('/workspaces/search?purpose=assign&q=ajeno')->json('data'))->all());
    }

    public function test_a_superuser_may_assign_to_any_enabled_workspace_even_outside_the_administrative_one(): void
    {
        $this->actAs('admin', superuser: true);
        $this->workspace('CLIENTE_X', 'Cliente X');
        $this->workspace('CLIENTE_OFF', 'Cliente off', null, active: false);

        $codes = collect($this->getJson('/workspaces/search?purpose=assign&q=cliente')->json('data'))->pluck('code')->all();

        $this->assertSame(['CLIENTE_X'], $codes);
    }

    public function test_the_roles_offered_follow_what_the_actor_may_hand_out(): void
    {
        Role::findOrCreate('manager', 'web');
        $this->seedRoleCatalog();
        Role::findByName('manager', 'web')->syncPermissions(['manage-users', 'view-dashboard']);
        $this->actAs('manager');

        $roles = $this->getJson('/workspaces/search?purpose=assign')->json('data.0.roles');

        $this->assertContains('manager', $roles);
        $this->assertNotContains('admin', $roles);
    }
}
