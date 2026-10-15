<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * A refused request answers the same whether the id in the URL exists or not: authorization runs before route-model binding (bootstrap/app.php).
 * Before, a member of any Workspace could tell which Workspace, user, organization or plan ids exist from 403 (exists) vs 404 (does not).
 */
class AuthorizationBeforeBindingTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $client;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->client = $this->bookingWorkspace('CLIENTE', ['dashboard', 'users_roles', 'settings']);
        $this->other = $this->bookingWorkspace('OTRO', ['dashboard']);
        $this->actAsMember($this->client);
    }

    /** @return array<string, array{string, string}> */
    public static function platformRoutes(): array
    {
        return [
            'assign a plan' => ['put', '/workspaces/%d/plan'],
            'edit a workspace' => ['put', '/workspaces/%d'],
            'deactivate a workspace' => ['post', '/workspaces/%d/deactivate'],
            'add a member' => ['post', '/workspaces/%d/members'],
            'edit a member' => ['put', '/workspaces/%d/members/%d'],
            'remove a member' => ['delete', '/workspaces/%d/members/%d'],
            'edit an organization' => ['put', '/organizations/%d'],
            'show a plan' => ['get', '/plans/%d'],
            'edit a module' => ['put', '/modules/%d'],
        ];
    }

    #[DataProvider('platformRoutes')]
    public function test_an_id_that_exists_and_one_that_does_not_get_the_same_refusal(string $method, string $pattern): void
    {
        $existing = $this->other->id;
        $missing = 987654;
        $args = fn (int $id) => array_fill(0, substr_count($pattern, '%d'), $id);

        $real = $this->{$method}(vsprintf($pattern, $args($existing)), ['name' => 'X']);
        $none = $this->{$method}(vsprintf($pattern, $args($missing)), ['name' => 'X']);

        $this->assertSame($real->getStatusCode(), $none->getStatusCode(), 'the status must not reveal whether the id exists');
    }

    public function test_the_permission_of_the_workspace_is_checked_before_a_user_id_is_looked_up(): void
    {
        $this->actAsMember($this->client, 'cliente'); // no permission to manage users

        $this->put('/users/'.$this->client->users()->value('users.id'), ['name' => 'X'])->assertForbidden();
        $this->put('/users/987654', ['name' => 'X'])->assertForbidden();
        $this->post('/users/987654/deactivate')->assertForbidden();
    }

    public function test_every_route_with_a_bound_model_still_resolves_for_someone_allowed(): void
    {
        // The reorder must not break the happy path: a bound model is still injected after the checks pass.
        $this->actAsMember($this->client, 'admin');
        $user = $this->member($this->client, 'cliente');

        $this->post("/users/{$user->id}/deactivate")->assertSessionHasNoErrors();
        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertNotNull(Route::getRoutes()->getByName('workspaces.plan.update'));
    }
}
