<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedFilterRequest;
use App\Services\SavedFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saves, updates (name and/or criteria) and deletes the search filters of the signed-in user in the active Workspace.
 * Every lookup starts from that user and Workspace (see SavedFilters), so another user's or Workspace's filter is a 404.
 * The module (`scope`) decides the permission and the criteria that are accepted.
 */
class SavedFilterController extends Controller
{
    public function __construct(private readonly SavedFilters $saved) {}

    public function store(SavedFilterRequest $request, string $scope): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        if ($this->saved->atLimit($request->user(), $workspace, $scope)) {
            return back()->withErrors(['name' => 'Alcanzaste el máximo de '.config('saved_filters.max_per_scope').' filtros guardados en este módulo. Elimina alguno para guardar otro.']);
        }

        $this->saved->create($request->user(), $workspace, $scope, trim($request->validated('name')), $request->cleaned());

        return back()->with('success', 'Filtros guardados correctamente');
    }

    public function update(SavedFilterRequest $request, string $scope, int $filter): RedirectResponse
    {
        $saved = $this->saved->owned($request->user(), $request->attributes->get('workspace'), $scope)->findOrFail($filter);

        if ($request->has('name')) {
            $saved->name = trim($request->validated('name'));
        }

        if ($request->has('criteria')) {
            $saved->criteria = $request->cleaned();
        }

        $saved->save();

        return back()->with('success', 'Filtro actualizado correctamente');
    }

    public function destroy(Request $request, string $scope, int $filter): RedirectResponse
    {
        abort_unless($this->saved->allows($scope, $request->attributes->get('workspace_permissions', [])), 403);

        $this->saved->owned($request->user(), $request->attributes->get('workspace'), $scope)->findOrFail($filter)->delete();

        return back()->with('success', 'Filtro eliminado correctamente');
    }
}
