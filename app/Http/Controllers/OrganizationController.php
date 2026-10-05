<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * An Organization is the company/client that owns Workspaces. Superusers (see the routes) can create
 * one and rename it; the name is a label, so renaming never touches the ID or any relation.
 * Organizations are never deleted.
 */
class OrganizationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $organization = Organization::create($this->validated($request));

        return $this->back('Organización creada correctamente');
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $organization->update($this->validated($request, $organization));

        return $this->back('Organización actualizada correctamente');
    }

    /** Disabling an Organization blocks access to all its Workspaces; nothing is deleted and it can be undone. */
    public function deactivate(Request $request, Organization $organization): RedirectResponse
    {
        if ($organization->is($request->attributes->get('workspace')->organization)) {
            throw ValidationException::withMessages(['status' => 'No puedes desactivar la organización del Workspace en el que estás trabajando.']);
        }

        if ($organization->workspaces()->get()->contains(fn ($workspace) => $workspace->isAdministrative())) {
            throw ValidationException::withMessages(['status' => 'La organización del Workspace administrativo de Ava Platform no se puede desactivar.']);
        }

        $organization->update(['is_active' => false]);

        return $this->back('Organización desactivada correctamente');
    }

    public function activate(Organization $organization): RedirectResponse
    {
        $organization->update(['is_active' => true]);

        return $this->back('Organización activada correctamente');
    }

    /** @return array{name: string} */
    private function validated(Request $request, ?Organization $current = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('organizations', 'name')->ignore($current?->id)],
        ]);
    }

    private function back(string $message): RedirectResponse
    {
        // Back to the page the user was on, so the page size and page of the list survive the change.
        return redirect()->back(fallback: route('users.index', ['tab' => 'organizaciones']))->with('success', $message);
    }
}
