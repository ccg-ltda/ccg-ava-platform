<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * There is no public self-registration: a Workspace is not an authorization by itself, so an account
 * can only be created by a Workspace admin (UserController), always inside that Workspace and with a role
 * the admin is allowed to assign.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_endpoint_does_not_create_users_or_choose_roles(): void
    {
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ])->assertNotFound();

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }
}
