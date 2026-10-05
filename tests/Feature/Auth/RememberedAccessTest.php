<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RememberedAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** "Recordar sesión" remembers the last email and Workspace (never the password), and the server re-validates both. */
class RememberedAccessTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $prd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $org = Organization::create(['name' => 'CCG']);
        $this->dev = Workspace::create(['organization_id' => $org->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->prd = Workspace::create(['organization_id' => $org->id, 'code' => 'OPERACION_PRD', 'name' => 'Operación']);
    }

    private function member(Workspace $workspace, string $role = 'cliente'): User
    {
        $user = User::factory()->create(['email' => 'Persona@Example.com']);
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function login(User $user, bool $remember, string $code = 'DESARROLLO_DEV')
    {
        $this->post('/pre-login', ['workspace_code' => $code]);

        return $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember]);
    }

    // --- remembering -----------------------------------------------------------------------------------------

    public function test_logging_in_with_remember_stores_the_email_and_workspace_but_never_the_password(): void
    {
        $user = $this->member($this->dev);

        $response = $this->login($user, remember: true)->assertSessionHasNoErrors();

        $response->assertCookie(RememberedAccess::EMAIL_COOKIE, 'persona@example.com');
        $response->assertCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringNotContainsString('password', (string) $cookie->getValue());
            $this->assertNotSame('password', $cookie->getValue());

            if (str_starts_with($cookie->getName(), 'ava_remembered_')) {
                $this->assertTrue($cookie->isHttpOnly(), "Cookie [{$cookie->getName()}] must be HttpOnly.");
            }
        }
    }

    public function test_logging_in_without_remember_forgets_what_was_remembered(): void
    {
        $user = $this->member($this->dev);

        $response = $this->withCookie(RememberedAccess::EMAIL_COOKIE, 'old@example.com')
            ->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->prd->id)
            ->post('/pre-login', ['workspace_code' => 'DESARROLLO_DEV']);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertCookieExpired(RememberedAccess::EMAIL_COOKIE);
        $response->assertCookieExpired(RememberedAccess::WORKSPACE_COOKIE);
    }

    public function test_a_failed_login_remembers_nothing(): void
    {
        $user = $this->member($this->dev);
        $this->post('/pre-login', ['workspace_code' => 'DESARROLLO_DEV']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong', 'remember' => true]);

        $response->assertSessionHasErrors('email');
        $response->assertCookieMissing(RememberedAccess::EMAIL_COOKIE);
        $response->assertCookieMissing(RememberedAccess::WORKSPACE_COOKIE);
    }

    // --- email -----------------------------------------------------------------------------------------------

    public function test_the_login_page_prefills_the_remembered_email(): void
    {
        $this->withSession(['pre_login_workspace_id' => $this->dev->id])
            ->withCookie(RememberedAccess::EMAIL_COOKIE, 'persona@example.com')
            ->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rememberedEmail', 'persona@example.com'));

        $this->withSession(['pre_login_workspace_id' => $this->dev->id])
            ->withCookie(RememberedAccess::EMAIL_COOKIE, 'not an email')
            ->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rememberedEmail', null));

        $this->withSession(['pre_login_workspace_id' => $this->dev->id])->get('/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rememberedEmail', null));
    }

    // --- Workspace -------------------------------------------------------------------------------------------

    public function test_pre_login_is_skipped_when_the_remembered_workspace_is_still_valid(): void
    {
        $this->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id)
            ->get('/pre-login')
            ->assertRedirect('/login');

        $this->assertSame($this->dev->id, session('pre_login_workspace_id'));
    }

    public function test_a_remembered_workspace_that_is_no_longer_valid_is_dropped(): void
    {
        $inactiveOrg = Organization::create(['name' => 'Cerrada', 'is_active' => false]);
        $ofInactiveOrg = Workspace::create(['organization_id' => $inactiveOrg->id, 'code' => 'CERRADA', 'name' => 'Cerrada']);
        $this->prd->update(['is_active' => false]);

        foreach ([(string) $this->prd->id, (string) $ofInactiveOrg->id, '999999', 'abc', '1 OR 1=1'] as $remembered) {
            $response = $this->withCookie(RememberedAccess::WORKSPACE_COOKIE, $remembered)->get('/pre-login');

            $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/PreLogin'));
            $response->assertCookieExpired(RememberedAccess::WORKSPACE_COOKIE);
        }
    }

    public function test_a_cookie_that_is_not_encrypted_by_the_server_is_ignored(): void
    {
        $this->withUnencryptedCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id)
            ->get('/pre-login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/PreLogin'));
    }

    public function test_changing_workspace_forgets_the_remembered_one(): void
    {
        $this->withSession(['pre_login_workspace_id' => $this->dev->id])
            ->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id)
            ->delete('/pre-login')
            ->assertRedirect('/pre-login')
            ->assertCookieExpired(RememberedAccess::WORKSPACE_COOKIE);
    }

    public function test_the_remembered_workspace_does_not_skip_the_membership_check(): void
    {
        $outsider = $this->member($this->prd);
        $this->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id)->get('/pre-login');

        $this->post('/login', ['email' => $outsider->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // --- a user brought back by Laravel's remember cookie ----------------------------------------------------

    public function test_a_returning_user_without_a_session_workspace_gets_the_remembered_one_after_validation(): void
    {
        $user = $this->member($this->dev);

        $this->actingAs($user)
            ->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id)
            ->get('/dashboard')
            ->assertOk();

        $this->assertSame($this->dev->id, session('workspace_id'));
    }

    public function test_without_a_remembered_workspace_the_user_still_goes_through_pre_login(): void
    {
        $user = $this->member($this->dev);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/pre-login');
        $this->assertGuest();
    }

    public function test_a_remembered_workspace_the_user_does_not_belong_to_is_rejected_and_forgotten(): void
    {
        $user = $this->member($this->dev);

        $response = $this->actingAs($user)
            ->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->prd->id)
            ->get('/dashboard');

        $response->assertRedirect('/pre-login');
        $response->assertCookieExpired(RememberedAccess::WORKSPACE_COOKIE);
        $this->assertGuest();
    }

    public function test_a_disabled_remembered_workspace_is_rejected_for_a_returning_user(): void
    {
        $user = $this->member($this->dev);
        $this->dev->update(['is_active' => false]);

        $this->actingAs($user)
            ->withCookie(RememberedAccess::WORKSPACE_COOKIE, (string) $this->dev->id)
            ->get('/dashboard')
            ->assertRedirect('/pre-login');

        $this->assertGuest();
    }
}
