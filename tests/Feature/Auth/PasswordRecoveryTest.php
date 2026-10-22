<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\MailAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Tests\TestCase;

/**
 * Account recovery: the answer never reveals whether an account exists, the token expires and works once, the endpoints
 * are rate limited, and the flow says so (instead of pretending) when mail cannot be delivered.
 */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('x');
        Notification::fake();
    }

    private function request(string $email, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/forgot-password', ['email' => $email]);
    }

    public function test_the_answer_is_identical_for_an_existing_an_unknown_and_an_inactive_account(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $answers = [
            $this->request($active->email, '10.0.0.1')->assertSessionHasNoErrors()->getSession()->get('status'),
            $this->request('nobody@example.com', '10.0.0.2')->assertSessionHasNoErrors()->getSession()->get('status'),
            $this->request($inactive->email, '10.0.0.3')->assertSessionHasNoErrors()->getSession()->get('status'),
        ];

        $this->assertCount(1, array_unique($answers));
        $this->assertStringContainsString('Si el correo corresponde a una cuenta activa', $answers[0]);
        $this->assertStringContainsString('60 minutos', $answers[0]);

        Notification::assertSentToTimes($active, ResetPasswordNotification::class, 1);
        Notification::assertNotSentTo($inactive, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_the_reset_email_is_queued_so_timing_does_not_tell_accounts_apart(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(ResetPasswordNotification::class));
    }

    public function test_the_email_must_be_a_valid_address(): void
    {
        $this->request('not-an-email')->assertSessionHasErrors('email');
        $this->request(str_repeat('a', 250).'@example.com')->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_when_mail_cannot_be_delivered_the_form_says_so_and_sends_nothing(): void
    {
        $user = User::factory()->create();
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);

        $this->assertFalse(MailAvailability::usable());

        // CSRF is only skipped in the testing environment, so send a token like a browser would.
        $this->withSession(['_token' => 't'])->post('/forgot-password', ['email' => $user->email, '_token' => 't'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('no está configurado', session('errors')->first('email'));
        $this->assertNull(session('status'));
        Notification::assertNothingSent();

        $this->get('/forgot-password')->assertInertia(fn ($page) => $page->where('mailUsable', false));
    }

    public function test_a_real_transport_with_a_real_sender_counts_as_configured(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.ccg.test', 'mail.from.address' => 'no-reply@ccg.test']);
        $this->assertTrue(MailAvailability::usable());

        config(['mail.from.address' => 'hello@example.com']); // the framework placeholder is not a sender
        $this->assertFalse(MailAvailability::usable());

        config(['mail.default' => 'array']);
        $this->assertFalse(MailAvailability::usable());
    }

    public function test_requests_are_limited_per_ip_and_per_address(): void
    {
        foreach (range(1, 5) as $i) {
            $this->request("user{$i}@example.com", '10.0.1.1')->assertSessionHasNoErrors();
        }
        $this->request('user6@example.com', '10.0.1.1')->assertStatus(429);

        // One mailbox cannot be flooded even from many addresses.
        foreach (range(1, 5) as $i) {
            $this->request('victim@example.com', "10.0.2.{$i}")->assertSessionHasNoErrors();
        }
        $this->request('victim@example.com', '10.0.2.99')->assertStatus(429);
        $this->request('VICTIM@example.com', '10.0.2.98')->assertStatus(429);
    }

    public function test_a_valid_token_sets_the_new_password_once_and_rotates_the_remember_token(): void
    {
        $user = User::factory()->create();
        $oldRemember = $user->remember_token;
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);

        // The token is single use.
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('brand-new-pass', $user->refresh()->password));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->assertFalse(Hash::check('brand-new-pass', $user->refresh()->password));
    }

    public function test_every_failure_shows_the_same_message(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $payload = fn (string $token, string $email) => ['token' => $token, 'email' => $email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];

        $this->post('/reset-password', $payload('wrong-token', $user->email))->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->post('/reset-password', $payload('x', 'nobody@example.com'))->assertSessionHasErrors(['email' => __('passwords.token')]);
        // A token of another account does not work for this one.
        $this->post('/reset-password', $payload(Password::createToken($other), $user->email))->assertSessionHasErrors(['email' => __('passwords.token')]);
        // An account that was deactivated after asking for the link cannot use it.
        $this->post('/reset-password', $payload(Password::createToken($inactive), $inactive->email))->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertFalse(Hash::check('brand-new-pass', $inactive->refresh()->password));
        $this->assertFalse(Hash::check('brand-new-pass', $user->refresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_strong_enough(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
    }

    public function test_production_requires_a_long_mixed_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $weak = Validator::make(['p' => 'alllowercase1'], ['p' => [PasswordRule::defaults()]]);
        $strong = Validator::make(['p' => 'Str0ngPassw0rd!'], ['p' => [PasswordRule::defaults()]]);

        $this->assertTrue($weak->fails());
        $this->assertFalse($strong->fails());
    }

    public function test_the_reset_endpoint_is_limited_per_ip(): void
    {
        $payload = ['token' => 'x', 'email' => 'nobody@example.com', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])->post('/reset-password', $payload)->assertSessionHasErrors('email');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])->post('/reset-password', $payload)->assertStatus(429);
    }

    public function test_the_login_page_offers_the_recovery_link_and_no_signup(): void
    {
        $this->get('/forgot-password')->assertOk();
        $this->get('/register')->assertNotFound();
        $source = file_get_contents(resource_path('js/Pages/Auth/Login.jsx'));
        $this->assertStringContainsString("route('password.request')", $source);
        $this->assertStringNotContainsString('Regístrese', $source);
    }

    public function test_a_non_string_email_is_a_validation_error_not_a_server_error(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.9.1'])->post('/forgot-password', ['email' => ['a@b.co']])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_the_email_is_retried_a_few_times_and_never_names_a_workspace_or_role(): void
    {
        $notification = new ResetPasswordNotification('tok');
        $this->assertSame(3, $notification->tries);
        $this->assertSame([30, 120], $notification->backoff);

        $mail = $notification->toMail(User::factory()->create(['email' => 'who@example.com']));
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines, [$mail->subject, $mail->actionUrl]));
        $this->assertStringContainsString('60 minutos', $text);
        $this->assertStringContainsString('/reset-password/tok', $text);
        $this->assertStringNotContainsString('workspace', strtolower($text));
    }

    public function test_a_session_opened_before_the_reset_is_closed_on_its_next_request(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;

        // The reset is done by a visitor with no session; "the other device" is a session that stored the hash it was opened with.
        $this->post('/reset-password', ['token' => Password::createToken($user), 'email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertSessionHasNoErrors();

        $this->withSession(['password_hash_web' => $oldHash])->actingAsWorkspaceMember($user->refresh())->get('/profile')->assertRedirect();
        $this->withSession(['password_hash_web' => $user->password])->actingAsWorkspaceMember($user)->get('/profile')->assertOk();
    }

    public function test_changing_the_password_in_the_profile_closes_the_other_sessions_but_not_this_one(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;

        $this->withSession(['password_hash_web' => $oldHash])->actingAsWorkspaceMember($user)->from('/profile')
            ->put('/password', ['current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-1', $user->password));
        // The session that made the change keeps working with the new hash...
        $this->withSession(['password_hash_web' => $user->password])->actingAsWorkspaceMember($user)->get('/profile')->assertOk();
        // ...and one still carrying the old hash is closed.
        $this->withSession(['password_hash_web' => $oldHash])->actingAsWorkspaceMember($user)->get('/profile')->assertRedirect();
    }

    public function test_an_account_gets_at_most_one_link_per_minute_whatever_the_number_of_requests(): void
    {
        $user = User::factory()->create();

        $this->request($user->email, '10.0.5.1');
        $this->request($user->email, '10.0.5.2');
        $this->request(strtoupper($user->email), '10.0.5.3');

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_a_delivery_that_fails_for_good_deletes_the_token_it_carried(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Password::createToken($user);
        Password::createToken($other);

        (new ResetPasswordNotification('tok'))->forUser($user->id)->failed(new \RuntimeException('smtp down'));

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);

        // Without a user nothing is touched.
        (new ResetPasswordNotification('tok'))->failed(new \RuntimeException('x'));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $other->email]);
    }

    public function test_the_reset_page_is_not_cached_and_sends_no_referer(): void
    {
        $response = $this->get('/reset-password/some-token?email=a@b.co')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_the_token_is_not_flashed_back_into_the_session_when_the_reset_fails(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', ['token' => 'secret-token-value', 'email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertSessionHasErrors('email');

        $this->assertNull(session()->getOldInput('token'));
        $this->assertStringNotContainsString('secret-token-value', json_encode(session()->all()));
    }

    public function test_no_message_to_the_visitor_contains_the_token_or_the_link(): void
    {
        $user = User::factory()->create();
        $answer = $this->request($user->email)->getSession()->get('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($answer) {
            $this->assertStringNotContainsString($notification->token, $answer);

            return true;
        });
        $this->assertStringNotContainsString('reset-password', $answer);
    }
}
