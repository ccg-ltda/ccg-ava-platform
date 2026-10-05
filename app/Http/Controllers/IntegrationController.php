<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveIntegrationRequest;
use App\Models\Integration;
use App\Services\IntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integraciones: external connections of the ACTIVE Workspace (taken from the request context, never from the
 * client). Every action requires `manage-settings`. Integrations are never deleted: they are deactivated, like
 * users, Workspaces and Organizations.
 */
class IntegrationController extends Controller
{
    public function __construct(private readonly IntegrationService $integrations) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Integrations/Index', [
            'integrations' => $this->integrations->list($request->attributes->get('workspace')),
            'catalog' => $this->integrations->catalog(),
        ]);
    }

    public function store(SaveIntegrationRequest $request): RedirectResponse
    {
        $this->integrations->create($request->attributes->get('workspace'), $request->validated());

        return $this->back('Integración creada correctamente');
    }

    public function update(SaveIntegrationRequest $request, int $integration): RedirectResponse
    {
        $this->integrations->update($request->current, $request->validated());

        return $this->back('Integración actualizada correctamente');
    }

    public function activate(Request $request, int $integration): RedirectResponse
    {
        $this->integrations->setActive($this->find($request, $integration), true);

        return $this->back('Integración activada correctamente');
    }

    public function deactivate(Request $request, int $integration): RedirectResponse
    {
        $this->integrations->setActive($this->find($request, $integration), false);

        return $this->back('Integración desactivada correctamente');
    }

    public function test(Request $request, int $integration): RedirectResponse
    {
        $integration = $this->find($request, $integration);

        // An inactive integration must not be used for real connections, and a test is one.
        if (! $integration->is_active) {
            return $this->back('Activa la integración para probar la conexión.', 'error');
        }

        $result = $this->integrations->test($integration);

        return $this->back($result->message, $result->ok ? 'success' : 'error');
    }

    /** The integration of the ACTIVE Workspace, or a 404: other Workspaces' ids do not exist from here. */
    private function find(Request $request, int $id): Integration
    {
        return $request->attributes->get('workspace')->integrations()->findOrFail($id);
    }

    private function back(string $message, string $tone = 'success'): RedirectResponse
    {
        return redirect()->back(fallback: route('integrations.index'))->with($tone, $message);
    }
}
