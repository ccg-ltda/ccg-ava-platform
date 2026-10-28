<?php

namespace App\Http\Controllers;

use App\Exceptions\ConversationStateException;
use App\Http\Requests\SendConversationMessageRequest;
use App\Models\Conversation;
use App\Services\ChatbotExecutions;
use App\Services\ConversationContext;
use App\Services\ConversationControl;
use App\Services\ConversationInbox;
use App\Services\ConversationMessenger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What an agent does on a conversation: take it, write to the contact, give it back to the AI, resolve it, and (a
 * manager) assign it. Always on the ACTIVE Workspace of the session (never on another one seen read only), so a
 * conversation of another Workspace is a 404. The routes require the permission; the state rules are in
 * ConversationControl and ConversationMessenger, which refuse what the state does not allow even if the page offered it.
 */
class ConversationActionController extends Controller
{
    public function __construct(
        private readonly ConversationInbox $inbox,
        private readonly ConversationControl $control,
        private readonly ConversationMessenger $messenger,
    ) {}

    public function take(Request $request, int $conversation): RedirectResponse
    {
        return $this->act($request, $conversation, fn (Conversation $c) => $this->control->take($c, $request->user()), 'Tomaste la conversación. La IA no responderá mientras la atiendas.');
    }

    public function assign(Request $request, int $conversation): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        // Only a member of THIS Workspace who can attend is a valid assignee; anything else is the same refusal.
        $assignee = $workspace->users()->whereKey($data['user_id'])->first();

        return $this->act($request, $conversation, function (Conversation $c) use ($assignee) {
            if (! $assignee) {
                throw new ConversationStateException('Ese usuario no puede atender conversaciones en este Workspace.');
            }

            return $this->control->assign($c, $assignee);
        }, 'Conversación asignada.');
    }

    public function release(Request $request, int $conversation): RedirectResponse
    {
        return $this->act($request, $conversation, function (Conversation $c) use ($request) {
            $returned = $this->control->returnToAi($c, $request->user(), $this->manages($request));
            // The AI picks up an unanswered message of the contact right away (the persisted state and the wider history go with it); a failure here never undoes the return.
            try {
                app(ChatbotExecutions::class)->resumeAfterReturn($returned, $request->boolean('continue'));
            } catch (Throwable $e) {
                Log::error('resume_after_return_failed', ['conversation' => $returned->id, 'exception' => $e::class]);
            }

            return $returned;
        }, 'La conversación volvió a la IA.');
    }

    public function resolve(Request $request, int $conversation): RedirectResponse
    {
        return $this->act($request, $conversation, fn (Conversation $c) => $this->control->resolve($c, $request->user(), $this->manages($request)), 'Conversación resuelta.');
    }

    public function message(SendConversationMessageRequest $request, int $conversation): RedirectResponse
    {
        return $this->act($request, $conversation, fn (Conversation $c) => $this->messenger->send($c, $request->user(), $this->manages($request), $request->validated('body')), null);
    }

    /**
     * The agent records or corrects a fact the contact gave while the agent has the conversation (name, phone...). Stored as «provided by the agent» and confirmed; it goes to the
     * workflow in the next payload, so the AI does not ask again. Only the agent who has it (or a manager) may; the key names are fixed words, never free text.
     */
    public function context(Request $request, int $conversation): RedirectResponse
    {
        $data = $request->validate([
            'facts' => ['required', 'array', 'min:1', 'max:20'],
            'facts.*' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (array_keys($data['facts']) as $key) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                throw ValidationException::withMessages(['facts' => 'El nombre del dato va en minúsculas, sin espacios ni acentos (por ejemplo: phone).']);
            }
        }

        return $this->act($request, $conversation, function (Conversation $c) use ($request, $data) {
            if (! $this->control->isOperator($c, $request->user(), $this->manages($request))) {
                throw new ConversationStateException('Solo el agente que atiende esta conversación puede registrar datos.');
            }

            app(ConversationContext::class)->apply($c, ['facts' => array_filter($data['facts'], fn ($v) => filled($v))], 'agent');
        }, 'Datos registrados.');
    }

    /** Finds the conversation in the active Workspace, runs the action and answers with a toast; a refused action is an error toast. */
    private function act(Request $request, int $id, Closure $action, ?string $success): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $conversation = $this->inbox->find($this->inbox->scope($workspace), $id);

        try {
            $action($conversation);
        } catch (ConversationStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $success ? back()->with('success', $success) : back();
    }

    private function manages(Request $request): bool
    {
        return in_array('manage-conversations', $request->attributes->get('workspace_permissions', []), true);
    }
}
