<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        $users = $workspace->users()->get();
        $roles = Role::all(['id', 'name']);

        return Inertia::render('Users/Index', [
            'users' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => [$user->pivot->role],
                    'created_at' => $user->created_at->format('d/m/Y'),
                ];
            }),
            'roles' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ]);

        $user->assignRole($request->role);
        $request->attributes->get('workspace')->users()->attach($user->id, ['role' => $request->role]);

        return redirect()->route('users.index');
    }

    public function update(Request $request, User $user)
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|exists:roles,name',
        ]);

        // name/email belong to the global account, not to the Workspace membership.
        // A Workspace admin may only change them for accounts that exist solely in this Workspace.
        if ($this->identityChanged($request, $user) && ! $this->canEditIdentity($request->user(), $user, $workspace)) {
            throw ValidationException::withMessages([
                'email' => 'Esta cuenta tiene acceso a otros Workspaces; solo un superusuario puede modificar su nombre o correo.',
            ]);
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        $user->syncRoles($request->role);
        $workspace->users()->updateExistingPivot($user->id, ['role' => $request->role]);

        return redirect()->route('users.index');
    }

    private function identityChanged(Request $request, User $user): bool
    {
        return trim($request->name) !== $user->name
            || Str::lower(trim($request->email)) !== Str::lower($user->email);
    }

    private function canEditIdentity(User $actor, User $target, Workspace $workspace): bool
    {
        if ($actor->is_superuser) {
            return true;
        }

        // Superusers can reach every Workspace, and other memberships mean other Workspaces rely on this account.
        return ! $target->is_superuser
            && ! $target->workspaces()->whereKeyNot($workspace->getKey())->exists();
    }

    public function destroy(Request $request, User $user)
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace->users()->whereKey($user->id)->exists(), 404);

        if ($request->user()->is($user)) {
            return redirect()->route('users.index');
        }

        // Removes the user from this Workspace only; the account may belong to other Workspaces.
        $workspace->users()->detach($user->id);

        return redirect()->route('users.index');
    }
}
