<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ListPagination;
use App\Services\RoleCatalog;
use App\Services\UserIdentityGuard;
use App\Services\WorkspaceAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class UserController extends Controller
{
    public function __construct(
        private readonly RoleCatalog $catalog,
        private readonly UserIdentityGuard $identity,
        private readonly WorkspaceAdministration $workspaces,
    ) {}

    /** Status filter of the users list. */
    private const STATUSES = ['all', 'active', 'inactive'];

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $workspace = $request->attributes->get('workspace');
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'all';

        // Each list is a closure so a partial reload (one tab paging) only computes that list.
        return Inertia::render('Users/Index', [
            'users' => fn () => $this->usersPage($request, $actor, $workspace, $search, $status),
            'filters' => ['search' => $search, 'status' => $status, 'perPage' => ListPagination::size($request, 'users')],
            'perPageOptions' => ListPagination::OPTIONS,
            // Workspaces (enabled, administered by the actor) where a new user may be created, with the roles allowed in each.
            'createTargets' => fn () => $this->workspaces->assignableTargets($actor)->map(fn ($target) => [
                'id' => $target->id,
                'name' => $target->name,
                'code' => $target->code,
                'isCurrent' => $target->is($workspace),
                'roles' => $this->workspaces->assignableRoles($actor, $target)->pluck('name')->values(),
            ])->values(),
            // The role catalog with the permissions each role grants (managed only by superusers).
            'roles' => fn () => $this->rolesPage($request, $workspace),
            // Permissions the system defines, with the roles that grant each (read-only matrix).
            'permissions' => fn () => $this->permissionsPage($request),
            // The role editor offers every permission, whatever page of the permissions list is showing.
            'permissionNames' => fn () => Permission::orderBy('name')->pluck('name'),
            'permissionRoles' => fn () => $this->catalog->all()->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values(),
            'canManageRoles' => $actor->can('manage-roles'),
            'workspaces' => fn () => $this->workspaces->overview($actor, $request),
            'organizations' => fn () => $this->workspaces->organizations($actor, $request),
        ]);
    }

    /** @return array<string, mixed> */
    private function usersPage(Request $request, User $actor, $workspace, string $search, string $status): array
    {
        $page = ListPagination::paginate(
            $workspace->users()
                ->when($search !== '', function ($query) use ($search) {
                    // "!" is the escape character so user input cannot act as a LIKE wildcard.
                    $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';

                    $query->where(fn ($q) => $q
                        ->whereRaw("lower(users.name) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(users.email) like ? escape '!'", [$term]));
                })
                ->when($status !== 'all', fn ($query) => $query->where('users.is_active', $status === 'active'))
                ->orderBy('users.name'),
            $request,
            'users',
        );

        $locked = $this->identity->lockedIds($actor, $page->getCollection(), $workspace);

        return [
            'data' => $page->getCollection()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => [$user->pivot->role],
                'created_at' => $user->created_at->format('d/m/Y'),
                'identityLocked' => in_array($user->id, $locked, true),
                'isActive' => $user->is_active,
                'canToggleStatus' => ! $actor->is($user) && ! in_array($user->id, $locked, true),
            ])->values(),
            'meta' => ListPagination::meta($page),
        ];
    }

    /** @return array<string, mixed> */
    private function rolesPage(Request $request, $workspace): array
    {
        $page = ListPagination::paginate($this->catalog->query(), $request, 'roles');
        $usersByRole = DB::table('workspace_user')
            ->where('workspace_id', $workspace->getKey())
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return [
            'data' => $page->getCollection()->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
                'protected' => $this->catalog->isProtected($role),
                'usersInWorkspace' => (int) ($usersByRole[$role->name] ?? 0),
            ])->values(),
            'meta' => ListPagination::meta($page),
        ];
    }

    /** @return array<string, mixed> */
    private function permissionsPage(Request $request): array
    {
        $page = ListPagination::paginate(Permission::with('roles:id,name')->orderBy('name'), $request, 'permissions');

        return [
            'data' => $page->getCollection()->map(fn ($permission) => [
                'name' => $permission->name,
                'roles' => $permission->roles->pluck('name')->all(),
            ])->values(),
            'meta' => ListPagination::meta($page),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        // The Workspace comes from the form but is only accepted among those the actor administers and that are enabled.
        $targets = $this->workspaces->assignableTargets($request->user());
        $request->validate(['workspace_id' => ['required', 'integer', Rule::in($targets->pluck('id')->all())]]);
        $target = $targets->firstWhere('id', $request->integer('workspace_id'));

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in($this->workspaces->assignableRoles($request->user(), $target)->pluck('name')->all())],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);

        $target->users()->attach($user->id, ['role' => $data['role']]);

        return redirect()->route('users.index')->with('success', 'Usuario creado correctamente');
    }

    /**
     * Edits the account (name, email, optional password) and its membership. The Workspace select moves the
     * membership: the `workspace_user` row of the current Workspace is replaced by one in the chosen Workspace
     * (same user ID and global identity; only that row changes). Without `workspace_id` nothing moves.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $current = $request->attributes->get('workspace');
        abort_unless($current->users()->whereKey($user->id)->exists(), 404);

        // Same rule as creation: only enabled Workspaces the actor administers, with the roles allowed in each.
        $targets = $this->workspaces->assignableTargets($actor);
        $request->validate(['workspace_id' => ['sometimes', 'integer', Rule::in($targets->pluck('id')->all())]]);
        $target = $request->filled('workspace_id') ? $targets->firstWhere('id', $request->integer('workspace_id')) : $current;
        $moving = ! $target->is($current);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in($this->workspaces->assignableRoles($actor, $target)->pluck('name')->all())],
        ]);

        $currentRole = $current->users()->whereKey($user->id)->first()->pivot->role;
        $changingPassword = filled($data['password'] ?? null);

        if ($actor->is($user) && ! $user->is_superuser) {
            if ($data['role'] !== $currentRole) {
                throw ValidationException::withMessages(['role' => 'No puedes cambiar tu propio rol.']);
            }

            if ($moving) {
                throw ValidationException::withMessages(['workspace_id' => 'No puedes cambiarte a ti mismo de Workspace.']);
            }
        }

        // Touching the role, the Workspace or the password of someone above the actor would be an escalation.
        if (($moving || $changingPassword || $data['role'] !== $currentRole)
            && ! $this->workspaces->canManageMember($actor, $current, $currentRole)) {
            throw ValidationException::withMessages(['role' => 'Este usuario tiene un rol con más permisos que el tuyo.']);
        }

        // Name, email and password belong to the global account: same protection for all three.
        if (($this->identityChanged($data, $user) || $changingPassword) && ! $this->identity->canEdit($actor, $user, $current)) {
            throw ValidationException::withMessages([
                'email' => 'Esta cuenta tiene acceso a otros Workspaces; solo un superusuario puede modificar su nombre, correo o contraseña.',
            ]);
        }

        if ($moving && $target->users()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['workspace_id' => 'Este usuario ya pertenece a ese Workspace.']);
        }

        DB::transaction(function () use ($user, $data, $changingPassword, $current, $target, $moving) {
            $user->name = $data['name'];
            $user->email = $data['email'];
            if ($changingPassword) {
                $user->password = $data['password']; // the model's `hashed` cast hashes it
            }
            $user->save();

            if ($moving) {
                $target->users()->attach($user->id, ['role' => $data['role']]);
                $current->users()->detach($user->id);
            } else {
                $current->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
            }
        });

        return redirect()->route('users.index')->with('success', 'Usuario actualizado correctamente');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorizeStatusChange($request, $user);
        $user->forceFill(['is_active' => false])->save();

        return redirect()->route('users.index')->with('success', 'Usuario desactivado correctamente');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorizeStatusChange($request, $user);
        $user->forceFill(['is_active' => true])->save();

        return redirect()->route('users.index')->with('success', 'Usuario activado correctamente');
    }

    /**
     * Status belongs to the global account, so it follows the same rule as name/email: a Workspace admin
     * cannot lock out accounts that also work in other Workspaces or superusers. Nothing is ever deleted.
     */
    private function authorizeStatusChange(Request $request, User $user): void
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['status' => 'No puedes cambiar el estado de tu propia cuenta.']);
        }

        if (! $this->identity->canEdit($request->user(), $user, $workspace)) {
            throw ValidationException::withMessages([
                'status' => 'Esta cuenta tiene acceso a otros Workspaces o es superusuario; solo un superusuario puede cambiar su estado.',
            ]);
        }
    }

    /** @param  array{name: string, email: string}  $data */
    private function identityChanged(array $data, User $user): bool
    {
        return trim($data['name']) !== $user->name
            || Str::lower(trim($data['email'])) !== Str::lower($user->email);
    }
}
