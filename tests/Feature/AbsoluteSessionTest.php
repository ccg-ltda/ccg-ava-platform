<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AbsoluteSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * S9 (RECOMENDACIONES.md §75): a signed-in session lasts at most 12 hours from the login, whatever the activity, on top of the inactivity limit that SESSION_LIFETIME
 * keeps. Reaching it signs the person out (account, roles and permissions untouched); API clients get 401, pages and Inertia a redirect; a "remember me" cookie cannot
 * bring the session back past the limit.
 */
class AbsoluteSessionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => 'CLIENTE_A', 'name' => 'Cliente A']);
        $this->user = User::factory()->create();
        $this->workspace->users()->attach($this->user->id, ['role' => 'admin']);
        Carbon::setTestNow(Carbon::parse('2026-03-02 08:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function login(bool $remember = false)
    {
        $this->post('/pre-login', ['workspace_code' => 'CLIENTE_A']);

        return $this->post('/login', ['email' => $this->user->email, 'password' => 'password'] + ($remember ? ['remember' => '1'] : []));
    }

    /** The browser was closed: the session is gone and the next request starts from a clean authentication state (a test shares one application between requests). */
    private function closeBrowser(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function advance(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    private function page(): int
    {
        return $this->get('/profile')->getStatusCode();
    }

    public function test_the_limit_is_twelve_hours_and_the_inactivity_limit_is_untouched(): void
    {
        $this->assertSame(720, config('auth.absolute_session_minutes'));
        $this->assertSame(120, (int) config('session.lifetime'));
        $this->assertSame(12 * 3600, AbsoluteSession::limitSeconds());
    }

    public function test_a_session_is_valid_before_twelve_hours_and_expires_at_the_limit(): void
    {
        $this->login()->assertRedirect();
        $this->assertSame(200, $this->page());

        $this->advance(11 * 60 + 59);
        $this->assertSame(200, $this->page());
        $this->assertAuthenticatedAs($this->user);

        $this->advance(2);
        $this->assertSame(302, $this->page(), 'a page asks the person to sign in again');
        $this->assertGuest();
    }

    public function test_continuous_activity_does_not_extend_the_absolute_duration(): void
    {
        $this->login();

        // A request every hour for eleven hours...
        for ($hour = 1; $hour <= 11; $hour++) {
            $this->advance(60);
            $this->assertSame(200, $this->page(), "hour {$hour}");
        }

        // ...and the twelfth hour is the end, activity or not.
        $this->advance(61);
        $this->assertSame(302, $this->page());
        $this->assertGuest();
    }

    public function test_a_normal_session_regeneration_does_not_restart_the_clock(): void
    {
        Route::middleware('web')->get('/__regenerate', function () {
            session()->regenerate();

            return response('ok');
        });

        $this->login();
        $this->advance(6 * 60);
        $this->get('/__regenerate')->assertOk();
        $this->assertSame(200, $this->page());

        $this->advance(6 * 60 + 1); // 12 h 01 min after the LOGIN, only 6 h 01 min after the regeneration
        $this->assertSame(302, $this->page());
        $this->assertGuest();
    }

    public function test_json_and_inertia_clients_get_the_right_answer_and_no_loop(): void
    {
        $this->login();
        $this->advance(12 * 60 + 1);

        $this->getJson('/notifications')->assertStatus(401)->assertHeader('Content-Type', 'application/json');
        $this->postJson('/support/messages', ['message' => 'hola', 'message_id' => 'abcdefgh1'])->assertStatus(401);

        // An Inertia visit is redirected to the sign-in (a 302 the client follows), never an HTML error page.
        $this->get('/profile', ['X-Inertia' => 'true', 'X-Inertia-Version' => 'x'])->assertRedirect(route('login'));

        // The sign-in itself is reachable and does not redirect back (no loop).
        $this->get('/login')->assertRedirect(route('pre-login')); // the workspace choice was part of the session that ended
        $this->get('/pre-login')->assertOk();
    }

    public function test_a_form_posted_with_an_expired_session_gets_the_sign_in_not_a_csrf_error(): void
    {
        $this->login();
        $this->advance(12 * 60 + 1);

        $this->withMiddleware()->patch('/profile', ['name' => 'Otro nombre', 'email' => $this->user->email])->assertRedirect();
        $this->assertGuest();
        $this->assertSame($this->user->fresh()->name === 'Otro nombre', false, 'nothing is saved with an expired session');
    }

    public function test_signing_in_again_starts_a_new_window_and_the_account_is_untouched(): void
    {
        $this->login();
        $this->advance(12 * 60 + 1);
        $this->assertSame(302, $this->page());

        $role = $this->workspace->users()->whereKey($this->user->id)->first()->pivot->role;

        $this->login()->assertRedirect();
        $this->assertSame(200, $this->page());
        $this->assertAuthenticatedAs($this->user);
        $this->assertTrue((bool) $this->user->fresh()->is_active);
        $this->assertSame($role, $this->workspace->users()->whereKey($this->user->id)->first()->pivot->role);

        $this->advance(11 * 60);
        $this->assertSame(200, $this->page());
        $this->advance(61);
        $this->assertSame(302, $this->page());
    }

    public function test_a_session_that_predates_the_rule_starts_counting_when_it_is_first_seen(): void
    {
        $this->actingAs($this->user)->withSession(['workspace_id' => $this->workspace->id]); // no start recorded: an old session

        $this->assertSame(200, $this->page());
        $this->advance(12 * 60 + 1);
        $this->assertSame(302, $this->page());
    }

    // --- remember me ---------------------------------------------------------------------------------------------------------------------

    /** The cookies a browser keeps after a remembered login (the remember cookie and the remembered e-mail and Workspace), as name => encrypted value. */
    private function recallerFrom($response): array
    {
        $cookies = collect($response->headers->getCookies())->reject(fn ($c) => in_array($c->getName(), [config('session.cookie'), 'XSRF-TOKEN'], true))->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()]);
        $this->assertTrue($cookies->keys()->contains(fn ($name) => str_starts_with($name, 'remember_web_')), 'a remembered login sets the remember cookie');

        return $cookies->all();
    }

    public function test_a_remember_cookie_restores_the_session_inside_the_window_but_never_past_it(): void
    {
        $cookies = $this->recallerFrom($this->login(true));

        // The browser was closed: the session is gone, the remember cookie is not. Two hours later it restores the session...
        $this->closeBrowser();
        $this->advance(2 * 60);
        $this->withUnencryptedCookies($cookies)->get('/profile')->assertOk();
        $this->assertAuthenticatedAs($this->user);

        // ...and the restored session INHERITS the original start: eleven hours after the login it is still there, twelve hours after it is not.
        $this->closeBrowser();
        $this->advance(9 * 60);
        $this->withUnencryptedCookies($cookies)->get('/profile')->assertOk();

        $this->closeBrowser();
        $this->advance(61);
        $this->withUnencryptedCookies($cookies)->get('/profile')->assertRedirect();
        $this->assertGuest();

        // The remember token was cycled: the same cookie can never bring the session back.
        $this->closeBrowser();
        $this->withUnencryptedCookies($cookies)->get('/profile')->assertRedirect();
        $this->assertGuest();
    }

    public function test_a_remember_cookie_whose_original_login_is_unknown_cannot_restore_a_session(): void
    {
        $cookies = $this->recallerFrom($this->login(true));

        $this->closeBrowser();
        $this->advance(13 * 60 + 1); // the server-side record of the original login is gone as well
        $this->withUnencryptedCookies($cookies)->get('/profile')->assertRedirect();
        $this->assertGuest();
    }

    // --- everything else keeps working ----------------------------------------------------------------------------------------------------

    public function test_public_pages_and_the_authentication_screens_are_unaffected_for_guests_and_for_an_expired_session(): void
    {
        $this->login();
        $this->advance(12 * 60 + 1);

        foreach (['/pre-login', '/legal', '/forgot-password', '/registro'] as $uri) {
            $this->get($uri)->assertOk();
        }

        $this->assertContains($this->post('/forgot-password', ['email' => 'nadie@example.test'])->getStatusCode(), [200, 302, 303], 'the recovery form answers normally (never 401, 419 or 500)');
        $this->assertGuest();
        $this->assertNull(Auth::user());
    }
}
