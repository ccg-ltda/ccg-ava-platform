<?php

namespace App\Http\Controllers;

use App\Exceptions\ConversationStateException;
use App\Http\Requests\SendConversationMessageRequest;
use App\Models\Conversation;
use App\Services\ConversationControl;
use App\Services\ConversationInbox;
use App\Services\ConversationMessenger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        return $this->act($request, $conversation, fn (Conversation $c) => $this->control->returnToAi($c, $request->user(), $this->manages($request)), 'La conversación volvió a la IA.');
    }

    public function resolve(Request $request, int $conversation): RedirectResponse
    {
        return $this->act($request, $conversation, fn (Conversation $c) => $this->control->resolve($c, $request->user(), $this->manages($request)), 'Conversación resuelta.');
    }

    public function message(SendConversationMessageRequest $request, int $conversation): RedirectResponse
    {
        return $this->act($request, $conversation, fn (Conversation $c) => $this->messenger->send($c, $request->user(), $this->manages($request), $request->validated('body')), null);
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
