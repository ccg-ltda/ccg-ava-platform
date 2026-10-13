<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveBookableResourceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Optional resources (a chair, a room) a service may need. Never deleted, only deactivated. */
class BookableResourceController extends Controller
{
    use HandlesBooking;

    public function store(SaveBookableResourceRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        return $this->attempt(function () use ($request, $workspace) {
            $this->assertOwnAgenda($workspace);
            $workspace->bookableResources()->create(['name' => trim($request->validated('name')), 'kind' => filled($request->validated('kind')) ? trim($request->validated('kind')) : null]);
        }, 'Recurso creado correctamente');
    }

    public function update(SaveBookableResourceRequest $request, int $resource): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->bookableResources()->findOrFail($resource);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $model->update(['name' => trim($request->validated('name')), 'kind' => filled($request->validated('kind')) ? trim($request->validated('kind')) : null]);
        }, 'Recurso actualizado correctamente');
    }

    public function activate(Request $request, int $resource): RedirectResponse
    {
        return $this->toggle($request, $resource, true);
    }

    public function deactivate(Request $request, int $resource): RedirectResponse
    {
        return $this->toggle($request, $resource, false);
    }

    private function toggle(Request $request, int $resource, bool $active): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->bookableResources()->findOrFail($resource);

        return $this->attempt(function () use ($workspace, $model, $active) {
            $this->assertOwnAgenda($workspace);
            $model->update(['is_active' => $active]);
        }, $active ? 'Recurso activado correctamente' : 'Recurso desactivado correctamente');
    }
}
