<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Isolated fixtures for browser (Playwright) validations that run against the development database.
 *
 * Everything an end-to-end run creates is tagged by a naming convention, so it can be found and removed
 * without touching real data:
 *
 *   users          *@e2e.ccg.test
 *   workspace      E2E_*   (organization "E2E Org"; "E2E Org*" names are cleaned too)
 *   roles          e2e_*   (create roles with this prefix during browser tests)
 *
 * Existing users, memberships, Workspaces and global roles are never modified.
 */
class E2eFixtures
{
    public const EMAIL_DOMAIN = '@e2e.ccg.test';

    public const ORGANIZATION = 'E2E Org';

    public const WORKSPACE_CODE = 'E2E_WS';

    public const ROLE_PREFIX = 'e2e_';

    private const WORKSPACE_PREFIX = 'E2E_';

    /**
     * Creates the Workspace and users and returns their credentials.
     *
     * @return array{workspace: string, users: list<array{email: string, password: string, role: string, superuser: bool}>}
     */
    public function setup(int $extraUsers = 0, bool $withSuperuser = false): array
    {
        $this->assertNotProduction();

        foreach (['admin', 'cliente'] as $role) {
            if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
                throw new RuntimeException("The [{$role}] role does not exist: the base data is missing, nothing was created.");
            }
        }

        return DB::transaction(function () use ($extraUsers, $withSuperuser) {
            $workspace = Workspace::firstOrCreate(
                ['code' => self::WORKSPACE_CODE],
                ['organization_id' => Organization::firstOrCreate(['name' => self::ORGANIZATION])->id, 'name' => 'E2E Workspace'],
            );

            $plan = [['admin', 'admin', false], ['viewer', 'cliente', false]];
            if ($withSuperuser) {
                $plan[] = ['super', 'admin', true];
            }
            for ($n = 1; $n <= $extraUsers; $n++) {
                $plan[] = ["user{$n}", 'cliente', false];
            }

            $users = [];
            foreach ($plan as [$name, $role, $superuser]) {
                $password = Str::password(16);
                $user = User::updateOrCreate(
                    ['email' => $name.self::EMAIL_DOMAIN],
                    ['name' => 'E2E '.ucfirst($name), 'password' => Hash::make($password), 'email_verified_at' => now()],
                );
                $user->forceFill(['is_superuser' => $superuser])->save();

                if ($superuser) {
                    // A superuser enters any Workspace without a membership; no role row is needed.
                    $workspace->users()->detach($user->id);
                } else {
                    $workspace->users()->syncWithoutDetaching([$user->id => ['role' => $role]]);
                }

                $users[] = ['email' => $user->email, 'password' => $password, 'role' => $role, 'superuser' => $superuser];
            }

            return ['workspace' => $workspace->code, 'users' => $users];
        });
    }

    /**
     * What a cleanup would remove (or did remove).
     *
     * @return array{users: int, memberships: int, workspaces: int, organizations: int, roles: int, audit: int}
     */
    public function pending(): array
    {
        return [
            'users' => $this->users()->count(),
            'memberships' => DB::table('workspace_user')
                ->where(fn ($q) => $q->whereIn('user_id', $this->users()->pluck('id'))->orWhereIn('workspace_id', $this->workspaces()->pluck('id')))
                ->count(),
            'workspaces' => $this->workspaces()->count(),
            'organizations' => Organization::where('name', 'like', self::ORGANIZATION.'%')->count(),
            'roles' => $this->roles()->count(),
            'audit' => $this->auditLogs()->count(),
        ];
    }

    /**
     * Removes only what carries the e2e naming convention, inside one transaction.
     *
     * @return array{users: int, memberships: int, workspaces: int, organizations: int, roles: int, audit: int} what was removed
     */
    public function cleanup(): array
    {
        $this->assertNotProduction();

        return DB::transaction(function () {
            $removed = $this->pending();

            $userIds = $this->users()->pluck('id');
            $workspaceIds = $this->workspaces()->pluck('id');

            DB::table('workspace_user')
                ->where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('workspace_id', $workspaceIds))
                ->delete();

            // A role still assigned to someone else is never deleted (it would lock that user out).
            $removed['roles'] = 0;
            $this->roles()->get()->each(function (Role $role) use (&$removed) {
                if (! DB::table('workspace_user')->where('role', $role->name)->exists()) {
                    $role->delete();
                    $removed['roles']++;
                }
            });

            // Logos uploaded from Configuraciones live in the storage disk, not in the database.
            $workspaceIds->each(fn (int $id) => Storage::deleteDirectory("workspaces/{$id}"));

            // The audit history of the e2e Workspaces and users (the rows survive their deletion by design).
            $this->auditLogs()->delete();

            User::whereIn('id', $userIds)->delete();
            Workspace::whereIn('id', $workspaceIds)->delete();
            Organization::where('name', 'like', self::ORGANIZATION.'%')->doesntHave('workspaces')->delete();

            return $removed;
        });
    }

    private function auditLogs()
    {
        return AuditLog::where(fn ($q) => $q->where('workspace_code', 'like', self::WORKSPACE_PREFIX.'%')->orWhere('user_email', 'like', '%'.self::EMAIL_DOMAIN));
    }

    private function users()
    {
        return User::where('email', 'like', '%'.self::EMAIL_DOMAIN);
    }

    private function workspaces()
    {
        return Workspace::where('code', 'like', 'E2E%')->get()
            ->filter(fn (Workspace $workspace) => str_starts_with($workspace->code, self::WORKSPACE_PREFIX))
            ->pipe(fn ($found) => Workspace::whereIn('id', $found->pluck('id')));
    }

    private function roles()
    {
        return Role::where('name', 'like', 'e2e%')->get()
            ->filter(fn (Role $role) => str_starts_with($role->name, self::ROLE_PREFIX))
            ->pipe(fn ($found) => Role::whereIn('id', $found->pluck('id')));
    }

    private function assertNotProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('E2E fixtures are not available in production.');
        }
    }
}
