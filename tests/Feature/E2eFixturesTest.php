<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\E2eFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class E2eFixturesTest extends TestCase
{
    use RefreshDatabase;

    private E2eFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->fixtures = app(E2eFixtures::class);
    }

    /** Real data that must survive every setup/cleanup. */
    private function realData(): array
    {
        $org = Organization::create(['name' => 'CCG']);
        $workspace = Workspace::create(['organization_id' => $org->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Desarrollo']);
        $user = User::factory()->create(['email' => 'real@ccg.com']);
        $workspace->users()->attach($user->id, ['role' => 'admin']);

        return [$workspace, $user];
    }

    private function snapshot(): array
    {
        return [
            'users' => User::orderBy('id')->get(['id', 'email', 'password', 'is_superuser'])->toArray(),
            'workspaces' => Workspace::orderBy('id')->get(['id', 'code', 'organization_id'])->toArray(),
            'organizations' => Organization::orderBy('id')->get(['id', 'name'])->toArray(),
            'memberships' => DB::table('workspace_user')->orderBy('id')->get(['workspace_id', 'user_id', 'role'])->toArray(),
            'roles' => Role::orderBy('id')->get(['id', 'name'])->toArray(),
        ];
    }

    public function test_setup_creates_an_isolated_workspace_and_users_with_hashed_passwords(): void
    {
        $result = $this->fixtures->setup(extraUsers: 2, withSuperuser: true);

        $this->assertSame('E2E_WS', $result['workspace']);
        $this->assertCount(5, $result['users']);
        $workspace = Workspace::where('code', 'E2E_WS')->firstOrFail();
        $this->assertSame('E2E Org', $workspace->organization->name);

        foreach ($result['users'] as $row) {
            $user = User::where('email', $row['email'])->firstOrFail();
            $this->assertStringEndsWith('@e2e.ccg.test', $user->email);
            $this->assertTrue(Hash::check($row['password'], $user->password));
            $this->assertSame($row['superuser'], $user->is_superuser);
        }

        $this->assertSame('admin', $workspace->users()->where('email', 'admin@e2e.ccg.test')->first()->pivot->role);
        $this->assertSame(4, $workspace->users()->count(), 'The superuser has no membership row.');
    }

    public function test_cleanup_removes_every_e2e_record_and_nothing_else(): void
    {
        $this->realData();
        $before = $this->snapshot();

        $this->fixtures->setup(extraUsers: 3, withSuperuser: true);
        Role::findOrCreate('e2e_created_in_browser', 'web');

        $removed = $this->fixtures->cleanup();

        $this->assertSame(6, $removed['users']);
        $this->assertSame(1, $removed['workspaces']);
        $this->assertSame(1, $removed['organizations']);
        $this->assertSame(1, $removed['roles']);
        $this->assertEquals($before, $this->snapshot(), 'Pre-existing users, memberships, Workspaces and roles are untouched.');
        $this->assertSame(0, array_sum($this->fixtures->pending()));
    }

    public function test_cleanup_removes_the_audit_history_of_the_e2e_data_only(): void
    {
        $this->realData();
        $row = fn (string $workspace, string $email) => AuditLog::create([
            'workspace_code' => $workspace, 'workspace_name' => $workspace, 'user_name' => 'X', 'user_email' => $email, 'action' => 'created',
            'resource_type' => 'user', 'resource_label' => 'X', 'description' => 'Creó el usuario X', 'changes' => [],
        ]);
        $this->fixtures->setup(extraUsers: 1, withSuperuser: false);
        $row('E2E_WS', 'admin@e2e.ccg.test');
        $row('REAL', 'real@example.com');

        $this->assertSame(1, $this->fixtures->cleanup()['audit']);
        $this->assertSame(['REAL'], AuditLog::pluck('workspace_code')->all());
    }

    public function test_only_exact_conventions_match(): void
    {
        [$workspace, $user] = $this->realData();
        $lookalikes = [
            Role::findOrCreate('e2e', 'web'),
            Role::findOrCreate('e2eish', 'web'),
            Role::findOrCreate('qa_e2e_x', 'web'),
        ];
        $otherDomain = User::factory()->create(['email' => 'x@e2e.ccg.test.example.com']);
        $notE2e = Workspace::create(['organization_id' => $workspace->organization_id, 'code' => 'E2EWS', 'name' => 'Looks similar']);

        $this->fixtures->cleanup();

        foreach ($lookalikes as $role) {
            $this->assertDatabaseHas('roles', ['id' => $role->id]);
        }
        $this->assertDatabaseHas('users', ['id' => $otherDomain->id]);
        $this->assertDatabaseHas('workspaces', ['id' => $notE2e->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_a_real_user_attached_to_the_e2e_workspace_keeps_the_account(): void
    {
        [, $realUser] = $this->realData();
        $this->fixtures->setup();
        Workspace::where('code', 'E2E_WS')->first()->users()->attach($realUser->id, ['role' => 'cliente']);

        $this->fixtures->cleanup();

        $this->assertDatabaseHas('users', ['id' => $realUser->id]);
        $this->assertSame(['DESARROLLO_DEV'], $realUser->workspaces()->pluck('code')->all());
    }

    public function test_an_e2e_role_assigned_to_a_real_membership_is_not_deleted(): void
    {
        [$workspace, $realUser] = $this->realData();
        $role = Role::findOrCreate('e2e_in_use', 'web');
        $workspace->users()->updateExistingPivot($realUser->id, ['role' => 'e2e_in_use']);

        $removed = $this->fixtures->cleanup();

        $this->assertSame(0, $removed['roles']);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_setup_and_cleanup_are_idempotent(): void
    {
        $this->fixtures->setup(extraUsers: 1);
        $this->fixtures->setup(extraUsers: 1);

        $this->assertSame(3, User::where('email', 'like', '%@e2e.ccg.test')->count());

        $this->fixtures->cleanup();
        $second = $this->fixtures->cleanup();

        $this->assertSame(['users' => 0, 'memberships' => 0, 'workspaces' => 0, 'organizations' => 0, 'roles' => 0, 'audit' => 0], $second);
    }

    public function test_setup_creates_nothing_when_the_base_roles_are_missing(): void
    {
        Role::query()->delete();

        try {
            $this->fixtures->setup();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            $this->assertSame(0, User::count());
            $this->assertSame(0, Workspace::count());
        }
    }

    public function test_nothing_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        foreach ([fn () => $this->fixtures->setup(), fn () => $this->fixtures->cleanup()] as $action) {
            try {
                $action();
                $this->fail('Expected a RuntimeException.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('production', $e->getMessage());
            }
        }
    }

    public function test_commands_report_the_database_and_support_dry_run(): void
    {
        $this->fixtures->setup();

        $this->artisan('e2e:cleanup --dry-run')
            ->expectsOutputToContain('Database: sqlite')
            ->assertSuccessful();
        $this->assertSame(2, User::where('email', 'like', '%@e2e.ccg.test')->count(), 'Dry run removes nothing.');

        $this->artisan('e2e:cleanup')->expectsOutputToContain('No e2e records left.')->assertSuccessful();
        $this->assertSame(0, User::count());
    }
}
