<?php

namespace App\Services;

use App\Models\Workspace;
use Spatie\Permission\Models\Role;

/**
 * Figures shown on the Reportes page. Everything starts from the given Workspace,
 * so nothing from other Workspaces can leak into the report.
 */
class WorkspaceReport
{
    private const RECENT_USERS = 5;

    /**
     * @return array{stats: array<string, int>, roleBreakdown: list<array{role: string, count: int}>, recentUsers: list<array<string, mixed>>}
     */
    public function for(Workspace $workspace, string $role): array
    {
        $members = $workspace->users()->orderByDesc('users.created_at')->get();
        $byRole = $members->countBy(fn ($user) => $user->pivot->role);

        return [
            'stats' => [
                'users' => $members->count(),
                'admins' => $byRole->get('admin', 0),
                'rolesInUse' => $byRole->count(),
                'permissions' => Role::findByName($role, 'web')->permissions()->count(),
            ],
            'roleBreakdown' => $byRole
                ->sortDesc()
                ->map(fn (int $count, string $name) => ['role' => $name, 'count' => $count])
                ->values()
                ->all(),
            'recentUsers' => $members
                ->take(self::RECENT_USERS)
                ->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    'createdAt' => $user->created_at->format('d/m/Y'),
                ])
                ->values()
                ->all(),
        ];
    }
}
