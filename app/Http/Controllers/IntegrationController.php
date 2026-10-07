<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveChannelIntegrationRequest;
use App\Http\Requests\SaveIntegrationRequest;
use App\Http\Requests\SaveN8nIntegrationRequest;
use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use App\Services\ChatbotService;
use App\Services\IntegrationService;
use App\Services\WorkspaceScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integraciones: external connections of the ACTIVE Workspace (taken from the request context, never from the
 * client). Every action requires `manage-settings`. Integrations are never deleted: they are deactivated, like
 * users, Workspaces and Organizations. The page lists the global channel catalog with what the Workspace has
 * configured for each channel, and the Workspace's generic connections. A superuser of the administrative Workspace
 * may LOOK at one other Workspace (`?workspace=`, read only); changes always apply to the active Workspace.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrations,
        private readonly IntegrationRegistry $types,
        private readonly ChatbotService $chatbots,
        private readonly WorkspaceScope $scope,
    ) {}

    public function index(Request $request): Response
    {
        $active = $request->attributes->get('workspace');
        $viewing = $this->scope->resolveOne($request->user(), $active, $request->query('workspace'));

        return Inertia::render('Integrations/Index', [
            'channels' => $this->integrations->channels($viewing),
            'integrations' => $this->integrations->list($viewing),
            'automation' => $this->chatbots->automation($viewing) + ['connection' => $this->integrations->n8n($viewing)],
            // The token just generated for a chatbot (shown once, only to the Workspace that owns it).
            'newToken' => $viewing->is($active) && $request->session()->has('agent_token') ? ['chatbotId' => $request->session()->get('agent_token_chatbot'), 'token' => $request->session()->get('agent_token')] : null,
            'catalog' => $this->integrations->catalog(),
            'scope' => [
                'workspace' => ['id' => $viewing->id, 'name' => $viewing->name, 'code' => $viewing->code],
                'readOnly' => ! $viewing->is($active),
                'canChoose' => $this->scope->canChoose($request->user(), $active),
            ],
        ]);
    }

    public function updateN8n(SaveN8nIntegrationRequest $request): RedirectResponse
    {
        $this->integrations->saveN8n($request->attributes->get('workspace'), $request->validated());

        return $this->back('n8n guardado.');
    }

    public function disconnectN8n(Request $request): RedirectResponse
    {
        $this->integrations->disconnectN8n($request->attributes->get('workspace'));

        return $this->back('n8n desconectado. Ava ya no guarda su API Key.');
    }

    public function updateChannel(SaveChannelIntegrationRequest $request, string $channel): RedirectResponse
    {
        $this->integrations->saveChannel($request->attributes->get('workspace'), $channel, $request->validated());

        return $this->back('Canal configurado correctamente');
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

        if (! $this->types->get($integration->type)->supportsTest()) {
            return $this->back('Esta integración todavía no admite prueba de conexión.', 'error');
        }

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
