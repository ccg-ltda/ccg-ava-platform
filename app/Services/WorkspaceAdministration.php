<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Who may administer which Workspace (Organization -> Workspaces -> memberships).
 *
 * - Superusers administer every Workspace and are the only ones who create/edit Workspaces or
 *   add existing accounts to them.
 * - Anyone else administers the Workspaces where their membership role grants `manage-users`.
 *
 * Removing a member only deletes the `workspace_user` row; the global account is never touched.
 */
class WorkspaceAdministration
{
    public const MANAGE_PERMISSION = 'manage-users';

    public function __construct(private readonly RoleCatalog $catalog) {}

    /** @return Builder<Workspace> */
    public function administrable(User $actor): Builder
    {
        $query = Workspace::query();

        if ($actor->is_superuser) {
            return $query;
        }

        $roles = Role::where('guard_name', 'web')
            ->whereHas('permissions', fn (Builder $q) => $q->where('name', self::MANAGE_PERMISSION))
            ->pluck('name');

        return $query->available()->whereHas('users', fn (Builder $q) => $q
            ->where('users.id', $actor->getKey())
            ->whereIn('workspace_user.role', $roles));
    }

    /**
     * Workspaces where the actor may create users or add members: administrable AND enabled
     * (an inactive Workspace, or one of an inactive Organization, never receives new assignments).
     *
     * @return Collection<int, Workspace>
     */
    public function assignableTargets(User $actor): Collection
    {
        return $this->assignable($actor)->orderBy('name')->get();
    }

    /** @return Builder<Workspace> the enabled Workspaces the actor may assign people to (see assignableTargets) */
    public function assignable(User $actor): Builder
    {
        return $this->administrable($actor)->available();
    }

    public function canAdminister(User $actor, Workspace $workspace): bool
    {
        return $this->administrable($actor)->whereKey($workspace->getKey())->exists();
    }

    /** @return list<string> permissions the actor's role grants inside the Workspace */
    public function permissionsIn(User $actor, Workspace $workspace): array
    {
        $role = $actor->roleInWorkspace($workspace);

        return $role
            ? Role::findByName($role, 'web')->permissions->pluck('name')->all()
            : [];
    }

    /** @return Collection<int, Role> roles the actor may hand out inside the Workspace (no escalation) */
    public function assignableRoles(User $actor, Workspace $workspace): Collection
    {
        return $this->catalog->assignableBy($actor, $this->permissionsIn($actor, $workspace));
    }

    /** A member whose role is above the actor's own permissions cannot be modified by the actor. */
    public function canManageMember(User $actor, Workspace $workspace, string $memberRole): bool
    {
        return $this->assignableRoles($actor, $workspace)->contains('name', $memberRole);
    }

    /**
     * Data of the Workspaces tab: one page of the Workspaces the actor administers, plus the member detail
     * of the selected one (when it is among them).
     *
     * @return array<string, mixed>
     */
    public function overview(User $actor, Request $request): array
    {
        $page = ListPagination::paginate(
            $this->administrable($actor)->with('organization:id,name')->withCount('users')->orderBy('name'),
            $request,
            'workspaces',
        );

        $selectedId = $request->integer('workspace');
        $selected = $selectedId ? $this->administrable($actor)->find($selectedId) : null;

        return [
            'list' => [
                'data' => $page->getCollection()->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'code' => $workspace->code,
                    'name' => $workspace->name,
                    'organization' => $workspace->organization->name,
                    'isActive' => $workspace->is_active,
                    'membersCount' => $workspace->users_count,
                ])->values(),
                'meta' => ListPagination::meta($page),
            ],
            // Light list for the "new Workspace" form (not paginated: it is a choice list, not a table).
            'organizationOptions' => $actor->is_superuser
                ? Organization::orderBy('name')->get(['id', 'name'])
                : [],
            'canManage' => (bool) $actor->is_superuser,
            'selected' => $selected ? $this->detail($actor, $selected, $request) : null,
        ];
    }

    /**
     * One page of Organizations for the superuser's tab (null for everyone else).
     *
     * @return array<string, mixed>|null
     */
    public function organizations(User $actor, Request $request): ?array
    {
        if (! $actor->is_superuser) {
            return null;
        }

        $page = ListPagination::paginate(Organization::withCount('workspaces')->orderBy('name'), $request, 'organizations');

        return [
            'data' => $page->getCollection()->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'isActive' => $organization->is_active,
                'workspacesCount' => $organization->workspaces_count,
            ])->values(),
            'meta' => ListPagination::meta($page),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(User $actor, Workspace $workspace, Request $request): array
    {
        $assignable = $this->assignableRoles($actor, $workspace);
        $page = ListPagination::paginate($workspace->users()->orderBy('users.name'), $request, 'members');

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'assignableRoles' => $assignable->pluck('name')->values(),
            'members' => [
                'data' => $page->getCollection()->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                    'isActive' => $member->is_active,
                    'isSelf' => $actor->is($member),
                    'canManage' => $assignable->contains('name', $member->pivot->role),
                ])->values(),
                'meta' => ListPagination::meta($page),
            ],
        ];
    }
}
