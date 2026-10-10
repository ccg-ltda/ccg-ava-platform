<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Where a user lands after signing in: never on a page their role in the Workspace cannot open. */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role): TestResponse
    {
        $this->seedRoleCatalog();
        $workspace = Workspace::create(['organization_id' => Organization::create(['name' => 'Org'])->id, 'code' => 'LAND_WS', 'name' => 'Land']);
        $user = User::factory()->create(['password' => 'password']);
        $workspace->users()->attach($user->id, ['role' => $role]);

        $this->post('/pre-login', ['workspace_code' => 'LAND_WS']);

        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_a_role_with_the_dashboard_lands_on_it(): void
    {
        $this->loginAs('cliente')->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_role_without_the_dashboard_lands_on_its_first_menu_page_and_it_opens(): void
    {
        $this->seedRoleCatalog();
        Role::findOrCreate('operador', 'web')->syncPermissions(['view-conversations']);

        $this->loginAs('operador')->assertRedirect(route('conversations.index', absolute: false));
        $this->get('/dashboard')->assertForbidden();
        $this->get('/conversations')->assertOk();
    }

    public function test_a_role_with_no_permission_lands_on_the_profile(): void
    {
        Role::findOrCreate('vacio', 'web');

        $this->loginAs('vacio')->assertRedirect(route('profile.edit', absolute: false));
        $this->get('/profile')->assertOk();
    }

    public function test_the_order_follows_the_menu(): void
    {
        $landing = new LandingPage;

        $this->assertSame('chatbots.index', $landing->route(['manage-users', 'view-chatbots', 'view-conversations']));
        $this->assertSame('settings.index', $landing->route(['manage-users', 'manage-settings']));
        $this->assertSame('profile.edit', $landing->route([]));
    }

    /** @return array<string, array{string}> */
    public static function permissions(): array
    {
        return collect(['view-dashboard', 'view-users', 'view-chatbots', 'manage-chatbots', 'view-conversations', 'reply-conversations', 'manage-conversations', 'manage-settings', 'manage-users'])
            ->mapWithKeys(fn (string $permission) => [$permission => [$permission]])->all();
    }

    #[DataProvider('permissions')]
    public function test_whatever_single_permission_a_role_has_the_login_destination_opens(string $permission): void
    {
        $this->seedRoleCatalog();
        Role::findOrCreate('solo', 'web')->syncPermissions([$permission]);

        $response = $this->loginAs('solo');
        $target = $response->headers->get('Location');

        $this->assertNotNull($target);
        $this->get($target)->assertOk();
    }

    public function test_a_superuser_without_membership_lands_on_the_dashboard(): void
    {
        $this->seedRoleCatalog();
        $workspace = Workspace::create(['organization_id' => Organization::create(['name' => 'Org'])->id, 'code' => 'SU_WS', 'name' => 'Su']);
        $user = User::factory()->create(['password' => 'password']);
        $user->forceFill(['is_superuser' => true])->save();

        $this->post('/pre-login', ['workspace_code' => 'SU_WS']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    }
}
