<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\Mask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Who may administer which Workspace (Organization -> Workspaces -> memberships).
 *
 * - Superusers acting inside the administrative Workspace administer every Workspace and are the only ones who create/edit
 *   Workspaces or add existing accounts to them. Inside a client Workspace a superuser is limited like anyone else.
 * - Anyone else administers the Workspaces where their membership role grants `manage-users`.
 *
 * Removing a member only deletes the `workspace_user` row; the global account is never touched.
 */
class WorkspaceAdministration
{
    public const MANAGE_PERMISSION = 'manage-users';

    public function __construct(private readonly RoleCatalog $catalog, private readonly PlatformAccess $platform) {}

    /** @return Builder<Workspace> */
    public function administrable(User $actor): Builder
    {
        $query = Workspace::query();

        if ($actor->isPlatformSuperuser()) {
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

    /**
     * An internal account of CCG (a superuser, or anyone who belongs to the administrative Workspace) never receives a membership or a
     * new role in a CLIENT Workspace through the administration screens: a membership gives the full role of the client, so it would
     * bypass the temporary, justified and audited support access. Applies to everyone, to themselves and to colleagues alike.
     */
    public function assertMayReceive(Workspace $target, User $member, string $field): void
    {
        if ($target->isAdministrative() || ! $this->isInternalAccount($member)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Las cuentas internas de la plataforma no se añaden a un Workspace de cliente. Para consultar datos privados usa el acceso de soporte.',
        ]);
    }

    /**
     * The accounts that may be added to `$target` as members, as one query: the SAME rules the add-member action enforces (an active account that is
     * not a member yet and, for a client Workspace, not an internal account of the platform). The selector of the members form reads this and the
     * action re-checks it, so what the interface offers can never differ from what the server accepts, and nothing about an account outside it is revealed.
     *
     * @return Builder<User>
     */
    public function eligibleUsers(Workspace $target): Builder
    {
        $adminCode = Workspace::normalizeCode((string) config('workspace.admin_code'));

        return User::query()
            ->where('users.is_active', true)
            ->whereDoesntHave('workspaces', fn ($q) => $q->whereKey($target->getKey()))
            ->when(! $target->isAdministrative(), fn (Builder $q) => $q
                ->where('users.is_superuser', false)
                ->whereDoesntHave('workspaces', fn ($w) => $w->where('workspaces.code', $adminCode)));
    }

    public function isInternalAccount(User $user): bool
    {
        return $user->is_superuser
            || $user->workspaces()->get()->contains(fn (Workspace $workspace) => $workspace->isAdministrative());
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

        // A role missing from the catalog grants nothing (EnsureWorkspaceContext closes such a session).
        $granted = $role
            ? Role::with('permissions:id,name')->where('name', $role)->where('guard_name', 'web')->first()?->permissions->pluck('name')->all() ?? []
            : [];

        return $this->platform->withinWorkspace($granted, $workspace);
    }

    /** @return Collection<int, Role> roles the actor may hand out inside the Workspace (no escalation) */
    public function assignableRoles(User $actor, Workspace $workspace): Collection
    {
        return $this->catalog->assignableBy($actor, $this->permissionsIn($actor, $workspace), $workspace);
    }

    /**
     * A member whose role is above the actor's own permissions cannot be modified by the actor. A superuser's membership
     * (role, Workspace) is only touched by another superuser, like the rest of their account (UserIdentityGuard): a
     * Workspace admin could otherwise demote them to a weaker role inside that Workspace.
     */
    public function canManageMember(User $actor, Workspace $workspace, string $memberRole, ?User $member = null): bool
    {
        if ($member?->is_superuser && ! $actor->isPlatformSuperuser()) {
            return false;
        }

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
            'organizationOptions' => $actor->isPlatformSuperuser()
                ? Organization::orderBy('name')->get(['id', 'name'])
                : [],
            'canManage' => $actor->isPlatformSuperuser(),
            // Changing or removing a membership needs `assign-roles` in the Workspace the actor is working in.
            'canAssign' => in_array('assign-roles', $request->attributes->get('workspace_permissions', []), true),
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
        if (! $actor->isPlatformSuperuser()) {
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
        // The platform administrator who looks at another Workspace team needs to tell members apart, not to read their addresses.
        $maskEmails = $actor->isPlatformSuperuser() && ! $workspace->is($request->attributes->get('workspace'));
        $page = ListPagination::paginate($workspace->users()->orderBy('users.name'), $request, 'members');

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'assignableRoles' => $assignable->pluck('name')->values(),
            // Invitations are issued by the platform administration only (the routes require it again); the people it invited are listed to it, e-mail masked like the members.
            'canInvite' => $actor->isPlatformSuperuser() && ! $workspace->isAdministrative() && WorkspaceInvitations::ready()
                && in_array('create-users', $request->attributes->get('workspace_permissions', []), true) && in_array('assign-roles', $request->attributes->get('workspace_permissions', []), true),
            'invitations' => $actor->isPlatformSuperuser() ? app(WorkspaceInvitations::class)->overview($workspace, $maskEmails) : [],
            'members' => [
                'data' => $page->getCollection()->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $maskEmails ? Mask::email($member->email) : $member->email,
                    'role' => $member->pivot->role,
                    'isActive' => $member->is_active,
                    'isSelf' => $actor->is($member),
                    'canManage' => in_array('assign-roles', $request->attributes->get('workspace_permissions', []), true)
                        && $this->canManageMember($actor, $workspace, $member->pivot->role, $member),
                ])->values(),
                'meta' => ListPagination::meta($page),
            ],
        ];
    }
}
