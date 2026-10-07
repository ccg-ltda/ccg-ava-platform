<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NavigationPagesTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->actingAsWorkspaceMember($user, $role);

        return $user;
    }

    public function test_guests_are_sent_to_login_for_every_page(): void
    {
        foreach (['/dashboard', '/settings', '/integrations', '/users'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_admin_can_open_the_four_pages(): void
    {
        $this->member('admin');

        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Reports/Index'));
        $this->get('/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Index'));
        $this->get('/integrations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Integrations/Index'));
        $this->get('/users')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Users/Index'));
    }

    public function test_non_admin_roles_only_reach_reportes(): void
    {
        foreach (['supervisor', 'cliente'] as $role) {
            $this->member($role);

            $this->get('/dashboard')->assertOk();
            $this->get('/settings')->assertForbidden();
            $this->get('/integrations')->assertForbidden();
            $this->get('/users')->assertForbidden();
        }
    }

    public function test_reportes_only_counts_the_active_workspace(): void
    {
        $admin = $this->member('admin');
        $workspace = Workspace::firstOrFail();
        $other = Workspace::create([
            'organization_id' => Organization::firstOrFail()->id,
            'code' => 'OTHER_WS',
            'name' => 'Other',
        ]);

        $workspace->users()->attach(User::factory()->create()->id, ['role' => 'cliente']);
        $other->users()->attach(User::factory()->count(3)->create()->pluck('id')->all(), ['role' => 'cliente']);

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Index')
            ->where('stats.users', 2)
            ->where('stats.admins', 1)
            ->where('stats.rolesInUse', 2)
            ->has('roleBreakdown', 2)
            ->has('recentUsers', 2));
    }

    public function test_reportes_shows_the_permissions_of_the_role_in_the_workspace(): void
    {
        $this->member('cliente');

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('stats.permissions', 1));
    }

    public function test_users_page_lists_roles_with_their_permissions(): void
    {
        $this->member('admin');

        $this->get('/users')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Users/Index')
            ->where('roles.data.0.name', 'admin')
            ->where('roles.data.0.protected', true)
            ->has('roles.data.0.permissions', 7));
    }

    public function test_workspace_and_user_are_shared_with_every_page(): void
    {
        $this->member('admin');

        $this->get('/settings')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.code', 'TEST_WS')
            ->where('auth.user.roles', ['admin']));
    }
}
