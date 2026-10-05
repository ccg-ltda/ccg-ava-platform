<?php

namespace App\Http\Controllers;

use App\Services\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Manages the global role catalog. Only superusers reach it (`can:manage-roles` in the routes),
 * because a role is shared by every Workspace. Permissions are code-defined capabilities, so they
 * are assigned to roles here but not created.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleCatalog $catalog) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ], ['name.regex' => 'Usa solo minúsculas, números, guiones y guion bajo.']);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()->back(fallback: route('users.index', ['tab' => 'roles']))->with('success', 'Rol creado correctamente');
    }

    /** The name is immutable: workspace_user.role stores it. Only the permissions change. */
    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        if ($this->catalog->isProtected($role)) {
            throw ValidationException::withMessages(['permissions' => "El rol {$role->name} es del sistema y no se puede modificar."]);
        }

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        $role->syncPermissions(Permission::whereIn('name', $data['permissions'] ?? [])->where('guard_name', 'web')->get());

        return redirect()->back(fallback: route('users.index', ['tab' => 'roles']))->with('success', 'Rol actualizado correctamente');
    }
}
