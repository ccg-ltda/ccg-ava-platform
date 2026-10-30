<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveChannelIntegrationRequest;
use App\Http\Requests\SaveIntegrationRequest;
use App\Http\Requests\SaveN8nIntegrationRequest;
use App\Http\Requests\SaveProviderIntegrationRequest;
use App\Integrations\CalendarProviderType;
use App\Integrations\IntegrationRegistry;
use App\Models\Integration;
use App\Services\ChatbotService;
use App\Services\IntegrationService;
use App\Services\ModuleAccess;
use App\Services\WorkspaceScope;
use App\Webhooks\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Integraciones: external connections of the ACTIVE Workspace (taken from the request context, never from the
 * client). Every action has its own permission (see routes/web.php). An integration is deactivated, like users, Workspaces and
 * Organizations; only a generic connection made by hand that is already inactive can be retired for good. The page lists the global channel catalog with what the Workspace has
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
        private readonly ModuleAccess $modules,
        private readonly WebhookService $webhooks,
    ) {}

    public function index(Request $request): Response
    {
        $active = $request->attributes->get('workspace');
        $viewing = $this->scope->resolveOne($request->user(), $active, $request->query('workspace'));
        // Webhooks de salida (§78): only the ACTIVE Workspace's own, only for a role that may read them (never while looking at another Workspace).
        $showWebhooks = $viewing->is($active) && in_array('view-webhooks', $request->attributes->get('workspace_permissions', []), true);

        return Inertia::render('Integrations/Index', [
            'channels' => $this->integrations->channels($viewing),
            'integrations' => $this->integrations->list($viewing),
            // External agendas: only for a Workspace whose plan includes them (the routes enforce it too).
            'providers' => $this->modules->allows($viewing, 'calendar_providers') ? $this->integrations->providers($viewing) : [],
            'automation' => $this->chatbots->automation($viewing) + ['connection' => $this->integrations->n8n($viewing)],
            // The token just generated for a chatbot (shown once, only to the Workspace that owns it).
            'newToken' => $viewing->is($active) && $request->session()->has('agent_token') ? ['chatbotId' => $request->session()->get('agent_token_chatbot'), 'token' => $request->session()->get('agent_token')] : null,
            'catalog' => $this->integrations->catalog(),
            'webhooks' => $showWebhooks ? $this->webhooks->overview($viewing) : null,
            // The signing secret just created or rotated (shown once, to the person who did it).
            'newWebhookSecret' => $showWebhooks ? $request->session()->get('webhook_secret') : null,
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

    public function updateProvider(SaveProviderIntegrationRequest $request, string $provider): RedirectResponse
    {
        $this->integrations->saveProvider($request->attributes->get('workspace'), $provider, $request->validated());

        return $this->back('Agenda configurada correctamente');
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

    /** Retires a connection made by hand (an inactive generic one): see IntegrationService::remove for what is and is not allowed. */
    public function destroy(Request $request, int $integration): RedirectResponse
    {
        $integration = $this->find($request, $integration);
        $name = $integration->name;

        try {
            $this->integrations->remove($integration);
        } catch (ValidationException $e) {
            return $this->back($e->validator->errors()->first(), 'error');
        }

        return $this->back("La conexión «{$name}» se retiró de este Workspace.");
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
        $workspace = $request->attributes->get('workspace');
        $integration = $workspace->integrations()->findOrFail($id);

        // An external agenda is a commercial module: without it in the plan it cannot be tested, activated or used, whatever the role.
        abort_if($this->types->get($integration->type) instanceof CalendarProviderType && ! $this->modules->allows($workspace, 'calendar_providers'), 403, 'Este módulo no está incluido en el plan de tu Workspace.');

        return $integration;
    }

    private function back(string $message, string $tone = 'success'): RedirectResponse
    {
        return redirect()->back(fallback: route('integrations.index'))->with($tone, $message);
    }
}
