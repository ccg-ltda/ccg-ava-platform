<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceWithMember(User $user): void
    {
        $this->seedRoleCatalog();
        $workspace = Workspace::create([
            'organization_id' => Organization::create(['name' => 'Test Org'])->id,
            'code' => 'TEST_WS',
            'name' => 'Test Workspace',
        ]);
        $workspace->users()->attach($user->id, ['role' => 'cliente']);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertRedirect('/pre-login');

        $this->workspaceWithMember(User::factory()->create());
        $this->post('/pre-login', ['workspace_code' => 'TEST_WS']);

        $this->get('/login')->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();
        $this->workspaceWithMember($user);
        $this->post('/pre-login', ['workspace_code' => 'TEST_WS']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();
        $this->workspaceWithMember($user);
        $this->post('/pre-login', ['workspace_code' => 'TEST_WS']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAsWorkspaceMember($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/pre-login');
    }
}
