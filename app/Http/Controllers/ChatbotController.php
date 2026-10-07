<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ViewsWorkspace;
use App\Http\Requests\SaveChatbotRequest;
use App\Models\Chatbot;
use App\Services\AgentAccess;
use App\Services\ChatbotService;
use App\Services\PrivateImage;
use App\Services\WorkspaceScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chatbots of the ACTIVE Workspace (taken from the request context, never from the client). Reading needs
 * `view-chatbots`; creating, editing, activating and configuring channels needs `manage-chatbots`. Chatbots are never
 * deleted: they are deactivated. A superuser of the administrative Workspace may LOOK at one other Workspace
 * (`?workspace=`, read only); every change applies to the active Workspace, so a foreign id is a 404.
 */
class ChatbotController extends Controller
{
    use ViewsWorkspace;

    public function __construct(
        private readonly ChatbotService $chatbots,
        WorkspaceScope $scope,
    ) {
        $this->scope = $scope;
    }

    public function index(Request $request): InertiaResponse
    {
        [$viewing, $foreign] = $this->viewing($request);

        return Inertia::render('Chatbots/Index', [
            'chatbots' => $this->chatbots->list($viewing, $foreign),
            'scope' => $this->scopeProps($request, $viewing, $foreign, 'manage-chatbots'),
        ]);
    }

    public function show(Request $request, int $chatbot): InertiaResponse
    {
        [$viewing, $foreign] = $this->viewing($request);
        $chatbot = $viewing->chatbots()->findOrFail($chatbot);

        return Inertia::render('Chatbots/Show', [
            'chatbot' => $this->chatbots->form($chatbot, $foreign),
            // The plain token exists only in the flash of the request that generated it.
            'agentToken' => $this->chatbots->agentAccess($chatbot, $request->session()->get('agent_token')),
            'channels' => $this->chatbots->channelStates($chatbot),
            'instructionsMax' => config('chatbots.instructions_max'),
            'avatar' => ['maxKb' => config('chatbots.avatar.max_kb')],
            'scope' => $this->scopeProps($request, $viewing, $foreign, 'manage-chatbots'),
        ]);
    }

    public function store(SaveChatbotRequest $request): RedirectResponse
    {
        $chatbot = $this->chatbots->create($request->attributes->get('workspace'), $request->validated());

        return redirect()->route('chatbots.show', $chatbot->id)->with('success', 'Chatbot creado correctamente');
    }

    public function update(SaveChatbotRequest $request, int $chatbot): RedirectResponse
    {
        $this->chatbots->update($request->current, $request->validated(), $request->file('avatar'), $request->boolean('remove_avatar'));

        return $this->back($chatbot, 'Chatbot actualizado correctamente');
    }

    public function activate(Request $request, int $chatbot): RedirectResponse
    {
        $this->chatbots->setActive($this->find($request, $chatbot), true);

        return $this->back($chatbot, 'Chatbot activado correctamente');
    }

    public function deactivate(Request $request, int $chatbot): RedirectResponse
    {
        $this->chatbots->setActive($this->find($request, $chatbot), false);

        return $this->back($chatbot, 'Chatbot desactivado correctamente');
    }

    public function updateChannel(Request $request, int $chatbot, string $channel): RedirectResponse
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        try {
            $this->chatbots->setChannelActive($this->find($request, $chatbot), $channel, (bool) $active);
        } catch (ValidationException $exception) {
            return $this->back($chatbot, $exception->validator->errors()->first(), 'error');
        }

        return $this->back($chatbot, $active ? 'Canal activado correctamente' : 'Canal desactivado correctamente');
    }

    /** Generates (or replaces) the token the automation uses to read this chatbot. It is shown once, on the next page. */
    public function generateAgentToken(Request $request, int $chatbot, AgentAccess $access): RedirectResponse
    {
        $request->session()->flash('agent_token', $access->generate($this->find($request, $chatbot)));

        return $this->back($chatbot, 'Token generado. Cópialo ahora: no se volverá a mostrar.');
    }

    public function revokeAgentToken(Request $request, int $chatbot, AgentAccess $access): RedirectResponse
    {
        $access->revoke($this->find($request, $chatbot));

        return $this->back($chatbot, 'Token revocado: la automatización ya no puede leer este chatbot.');
    }

    public function avatar(Request $request, int $chatbot): Response|StreamedResponse
    {
        [$viewing] = $this->viewing($request);

        return PrivateImage::response($viewing->chatbots()->findOrFail($chatbot)->avatar_path, $request);
    }

    /** The chatbot of the ACTIVE Workspace, or a 404: other Workspaces' ids do not exist from here. */
    private function find(Request $request, int $id): Chatbot
    {
        return $request->attributes->get('workspace')->chatbots()->findOrFail($id);
    }

    private function back(int $chatbot, string $message, string $tone = 'success'): RedirectResponse
    {
        return redirect()->back(fallback: route('chatbots.show', $chatbot))->with($tone, $message);
    }
}
