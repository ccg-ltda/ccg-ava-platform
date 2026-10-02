<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RoleCatalog;
use App\Services\UserIdentityGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class UserController extends Controller
{
    public function __construct(
        private readonly RoleCatalog $catalog,
        private readonly UserIdentityGuard $identity,
    ) {}

    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $workspace = $request->attributes->get('workspace');
        $search = trim((string) $request->query('search', ''));

        $page = $workspace->users()
            ->when($search !== '', function ($query) use ($search) {
                // "!" is the escape character so user input cannot act as a LIKE wildcard.
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';

                $query->where(fn ($q) => $q
                    ->whereRaw("lower(users.name) like ? escape '!'", [$term])
                    ->orWhereRaw("lower(users.email) like ? escape '!'", [$term]));
            })
            ->orderBy('users.name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $locked = $this->identity->lockedIds($actor, $page->getCollection(), $workspace);
        $assignable = $this->catalog->assignableBy($actor, $request->attributes->get('workspace_permissions', []));
        $canManageRoles = $actor->can('manage-roles');
        $usersByRole = DB::table('workspace_user')
            ->where('workspace_id', $workspace->getKey())
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return Inertia::render('Users/Index', [
            'users' => [
                'data' => $page->getCollection()->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => [$user->pivot->role],
                    'created_at' => $user->created_at->format('d/m/Y'),
                    'identityLocked' => in_array($user->id, $locked, true),
                ])->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem() ?? 0,
                    'to' => $page->lastItem() ?? 0,
                ],
            ],
            'filters' => ['search' => $search],
            // Roles the actor may assign to users of this Workspace.
            'assignableRoles' => $assignable->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values(),
            // Whole catalog with the permissions each role grants (managed only by superusers).
            'roles' => $this->catalog->all()
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->all(),
                    'protected' => $this->catalog->isProtected($role),
                    'usersInWorkspace' => (int) ($usersByRole[$role->name] ?? 0),
                    'inUse' => $canManageRoles ? $this->catalog->isInUse($role) : null,
                ])->values(),
            'permissions' => Permission::orderBy('name')->pluck('name'),
            'canManageRoles' => $canManageRoles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', 'string', Rule::in($this->assignableNames($request))],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);

        $request->attributes->get('workspace')->users()->attach($user->id, ['role' => $data['role']]);

        return redirect()->route('users.index')->with('success', "Usuario {$user->name} creado.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'role' => ['required', 'string', Rule::in($this->assignableNames($request))],
        ]);

        $currentRole = $workspace->users()->whereKey($user->id)->first()->pivot->role;

        if ($request->user()->is($user) && $data['role'] !== $currentRole && ! $user->is_superuser) {
            throw ValidationException::withMessages(['role' => 'No puedes cambiar tu propio rol.']);
        }

        if ($this->identityChanged($data, $user) && ! $this->identity->canEdit($request->user(), $user, $workspace)) {
            throw ValidationException::withMessages([
                'email' => 'Esta cuenta tiene acceso a otros Workspaces; solo un superusuario puede modificar su nombre o correo.',
            ]);
        }

        $user->update(['name' => $data['name'], 'email' => $data['email']]);
        $workspace->users()->updateExistingPivot($user->id, ['role' => $data['role']]);

        return redirect()->route('users.index')->with('success', "Usuario {$user->name} actualizado.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        if ($request->user()->is($user)) {
            return redirect()->route('users.index');
        }

        // Removes the user from this Workspace only; the account may belong to other Workspaces.
        $workspace->users()->detach($user->id);

        return redirect()->route('users.index')->with('success', "{$user->name} ya no tiene acceso a este Workspace.");
    }

    /** @return list<string> */
    private function assignableNames(Request $request): array
    {
        return $this->catalog
            ->assignableBy($request->user(), $request->attributes->get('workspace_permissions', []))
            ->pluck('name')
            ->all();
    }

    /** @param  array{name: string, email: string}  $data */
    private function identityChanged(array $data, User $user): bool
    {
        return trim($data['name']) !== $user->name
            || Str::lower(trim($data['email'])) !== Str::lower($user->email);
    }
}
