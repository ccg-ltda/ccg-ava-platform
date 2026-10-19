<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Changing one's own password from the profile: authenticated, current password required, only the own record, limited. */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function change(User $user, array $data)
    {
        return $this->actingAsWorkspaceMember($user)->from('/profile')->put('/password', $data);
    }

    public function test_it_changes_only_the_own_password_and_rotates_the_remember_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherHash = $other->password;
        $remember = $user->remember_token;

        $this->change($user, ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-1', $user->refresh()->password));
        $this->assertNotSame($remember, $user->remember_token);
        $this->assertSame($otherHash, $other->refresh()->password);
    }

    public function test_the_current_password_is_required_and_must_be_right(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        $this->change($user, ['password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertSessionHasErrors('current_password');
        $this->change($user, ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertSessionHasErrors('current_password');

        $this->assertSame($hash, $user->refresh()->password);
    }

    public function test_the_new_password_must_differ_be_confirmed_and_meet_the_rules(): void
    {
        $user = User::factory()->create();

        $this->change($user, ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])->assertSessionHasErrors('password');
        $this->change($user, ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'nope'])->assertSessionHasErrors('password');
        $this->change($user, ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_a_guest_cannot_change_a_password(): void
    {
        $this->put('/password', ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertRedirect(route('login'));
    }

    public function test_guessing_the_current_password_is_limited(): void
    {
        RateLimiter::clear('x');
        $user = User::factory()->create();

        foreach (range(1, 6) as $i) {
            $this->change($user, ['current_password' => "guess{$i}", 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertSessionHasErrors('current_password');
        }
        $this->change($user, ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertStatus(429);
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
