<?php

namespace App\Http\Controllers;

use App\Exceptions\ConversationStateException;
use App\Exceptions\DemoNotAllowed;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationInbox;
use App\Services\DemoConversations;
use App\Services\DemoSimulator;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The demo bench inside the inbox: generate / reset / clean the scenarios, and simulate traffic on a DEMO conversation.
 * Every route needs `manage-conversations`; nothing exists outside the environments of config/demo.php (404) and the
 * generator only works in the administrative Workspace of the session. Simulation acts only on conversations marked as
 * demo of the ACTIVE Workspace, and never calls a provider.
 */
class DemoConversationController extends Controller
{
    public function __construct(
        private readonly DemoConversations $demo,
        private readonly DemoSimulator $simulator,
        private readonly ConversationInbox $inbox,
    ) {}

    public function manage(Request $request, string $action): RedirectResponse
    {
        abort_unless($this->demo->enabled() && in_array($action, ['generate', 'reset', 'clean'], true), 404);

        $workspace = $request->attributes->get('workspace');

        try {
            $message = match ($action) {
                'generate' => $this->generated($this->demo->generate($workspace)),
                'reset' => 'Escenarios demo reiniciados. '.$this->generated($this->demo->reset($workspace)),
                'clean' => "Conversaciones demo eliminadas: {$this->demo->clean($workspace)}.",
            };
        } catch (DemoNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    public function incoming(Request $request, int $conversation): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);

        return $this->simulate($request, $conversation, fn ($c) => $this->simulator->incoming($c, trim($data['body'])), 'Mensaje entrante simulado.');
    }

    public function aiReply(Request $request, int $conversation): RedirectResponse
    {
        return $this->simulate($request, $conversation, fn ($c) => $this->simulator->aiReply($c), 'Respuesta de IA simulada añadida.');
    }

    public function handoff(Request $request, int $conversation): RedirectResponse
    {
        return $this->simulate($request, $conversation, fn ($c) => $this->simulator->handoff($c), 'Solicitud de agente simulada.');
    }

    public function status(Request $request, int $conversation, int $message): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['sent', 'delivered', 'read', 'failed'])]]);

        return $this->simulate($request, $conversation, fn ($c) => $this->simulator->status($c, Message::where('conversation_id', $c->id)->findOrFail($message), $data['status']), 'Estado de entrega simulado.');
    }

    /** @param  Closure(Conversation): mixed  $action */
    private function simulate(Request $request, int $id, Closure $action, string $success): RedirectResponse
    {
        abort_unless($this->demo->enabled(), 404);

        $conversation = $this->inbox->find($this->inbox->scope($request->attributes->get('workspace')), $id);

        try {
            $action($conversation);
        } catch (ConversationStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** @param  array{created: int, existing: int, unassigned: int}  $result */
    private function generated(array $result): string
    {
        return "Escenarios creados: {$result['created']}; ya existían: {$result['existing']}."
            .($result['unassigned'] > 0 ? " {$result['unassigned']} quedaron pendientes porque el Workspace no tiene agentes." : '');
    }
}
