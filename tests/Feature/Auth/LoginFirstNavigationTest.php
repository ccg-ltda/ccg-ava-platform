<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The first page after signing in. Laravel remembers the page a GUEST tried to open (`url.intended`) and sends the person there after the login. In a multi-Workspace app that
 * page may belong to ANOTHER account or Workspace (a tab left open on the previous session keeps asking for its pages): the person is then sent to a page their role or plan
 * does not allow and sees a 403 on the very first navigation, while the Dashboard, reached by reloading, works. The landing is therefore always checked against the permissions and
 * the modules of the account that just signed in, never taken on trust. Nothing here grants access: the server still decides every request.
 */
class LoginFirstNavigationTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $this->client = $this->bookingWorkspace('CLIENTE_A', ['dashboard', 'conversations', 'service_catalog']);
        $this->person = User::factory()->create();
        $this->client->users()->attach($this->person->id, ['role' => 'cliente']);
    }

    private function enter(Workspace $workspace, User $user, string $password = 'password')
    {
        $this->post('/pre-login', ['workspace_code' => $workspace->code]);

        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    /** A page of the previous session that polled or navigated while nobody was signed in: Laravel stores it as the intended URL. */
    private function guestAsks(string $path, array $headers = []): void
    {
        $this->get($path, $headers)->assertRedirect();
        $this->assertSame(url($path), session('url.intended'), 'the guest request was remembered as the page to go to after the login');
    }

    public function test_a_stale_page_of_another_session_does_not_send_the_next_login_to_a_403(): void
    {
        // The role «cliente» of this Workspace can not open the users administration, nor the audit.
        $this->guestAsks('/users', ['X-Inertia' => 'true']);

        $response = $this->enter($this->client, $this->person);

        $this->assertAuthenticated();
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('/users', (string) $location, 'the remembered page is not allowed for this account');
        $this->followingRedirects()->get($location)->assertOk();
        $this->assertNull(session('url.intended'), 'the stale target is dropped, not kept for later');
    }

    public function test_the_first_navigation_after_the_login_is_never_a_403_whatever_the_stale_page_was(): void
    {
        foreach (['/users', '/audit', '/plans', '/integrations', '/settings'] as $path) {
            auth()->logout();
            $this->flushSession();
            $this->guestAsks($path, ['X-Inertia' => 'true']);

            $response = $this->enter($this->client, $this->person);
            $this->get($response->headers->get('Location'))->assertOk();
        }
    }

    public function test_a_remembered_page_the_account_may_open_is_still_honoured(): void
    {
        $admin = User::factory()->create();
        $this->client->users()->attach($admin->id, ['role' => 'admin']);
        $this->guestAsks('/conversations', ['X-Inertia' => 'true']);

        $response = $this->enter($this->client, $admin);

        $this->assertStringContainsString('/conversations', (string) $response->headers->get('Location'));
    }

    public function test_a_remembered_page_of_a_module_the_plan_lacks_is_dropped_even_if_the_role_has_the_permission(): void
    {
        $admin = User::factory()->create();
        $this->client->users()->attach($admin->id, ['role' => 'admin']); // the role may manage bookings, the plan of this Workspace does not include them
        $this->guestAsks('/bookings', ['X-Inertia' => 'true']);

        $response = $this->enter($this->client, $admin);

        $this->assertStringNotContainsString('/bookings', (string) $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))->assertOk();
    }

    public function test_the_remembered_page_of_a_json_endpoint_or_another_origin_is_never_a_landing(): void
    {
        foreach (['/conversations/alerts', '/support/thread', '/help/manuals/x'] as $path) {
            auth()->logout();
            $this->flushSession();
            $this->get($path)->assertRedirect(); // asked as a page, not as JSON: remembered
            $response = $this->enter($this->client, $this->person);
            $this->assertStringNotContainsString($path, (string) $response->headers->get('Location'), $path);
        }

        auth()->logout();
        $this->flushSession();
        $this->withSession(['url.intended' => 'https://evil.example/phish'])->post('/pre-login', ['workspace_code' => $this->client->code]);
        $response = $this->post('/login', ['email' => $this->person->email, 'password' => 'password']);
        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
    }

    public function test_the_denial_is_still_real_a_403_does_not_become_access_and_reloading_does_not_help(): void
    {
        $this->enter($this->client, $this->person)->assertRedirect();

        // Typed or followed by hand while signed in: the server refuses, every time, reload or not.
        $this->get('/users')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/audit')->assertForbidden();
        $this->get('/dashboard')->assertOk();
    }

    public function test_an_account_that_is_not_a_member_of_the_workspace_is_refused_and_a_stale_page_changes_nothing(): void
    {
        $stranger = User::factory()->create();
        $this->guestAsks('/dashboard', ['X-Inertia' => 'true']);

        $this->enter($this->client, $stranger)->assertSessionHasErrors();

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect();
    }

    public function test_an_inactive_membership_or_account_is_refused(): void
    {
        $this->person->forceFill(['is_active' => false])->save();

        $this->enter($this->client, $this->person)->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_a_person_in_several_workspaces_lands_in_the_one_they_chose_with_that_workspaces_rights(): void
    {
        $both = User::factory()->create();
        $this->client->users()->attach($both->id, ['role' => 'cliente']);
        $this->dev->users()->attach($both->id, ['role' => 'admin']);
        $this->guestAsks('/plans', ['X-Inertia' => 'true']); // a page of the administrative Workspace

        // Signing in to the CLIENT Workspace: the administrative page remembered from the other session must not follow them there.
        $response = $this->enter($this->client, $both);
        $this->assertStringNotContainsString('/plans', (string) $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))->assertOk();
        $this->assertSame($this->client->id, session('workspace_id'));
        $this->get('/plans')->assertForbidden(); // and the client Workspace still refuses it

        // Signing in to DESARROLLO_DEV keeps the internal administration whole.
        auth()->logout();
        $this->flushSession();
        $this->enter($this->dev, $both)->assertRedirect();
        $this->assertSame($this->dev->id, session('workspace_id'));
    }

    public function test_the_session_regenerated_at_login_carries_the_workspace_so_the_first_request_needs_no_second_try(): void
    {
        $before = null;
        $this->get('/pre-login');
        $before = session()->getId();

        $response = $this->enter($this->client, $this->person);

        $this->assertNotSame($before, session()->getId(), 'the session id was regenerated');
        $this->assertSame($this->client->id, session('workspace_id'));
        $this->get($response->headers->get('Location'))->assertOk();
        $this->get($response->headers->get('Location'))->assertOk(); // and a second, identical request behaves the same
    }
}
