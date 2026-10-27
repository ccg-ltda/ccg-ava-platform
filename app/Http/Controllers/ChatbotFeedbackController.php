<?php

namespace App\Http\Controllers;

use App\Services\ChatbotFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Esta respuesta no me gustó»: a person flags a reply of the assistant in a conversation and proposes the new content of ONE instruction block. It only creates a
 * proposal; applying it is a separate, reviewed step (ChatbotInstructionController::approve). The conversation and the message come from the Workspace of the session.
 */
class ChatbotFeedbackController extends Controller
{
    public function __construct(private readonly ChatbotFeedback $feedback) {}

    public function store(Request $request, int $conversation, int $message): RedirectResponse
    {
        abort_unless(ChatbotFeedback::ready(), 404);

        $workspace = $request->attributes->get('workspace');
        $conversationModel = $workspace->conversations()->findOrFail($conversation);
        $messageModel = $conversationModel->messages()->findOrFail($message);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'desired_behavior' => ['nullable', 'string', 'max:1000'],
            'target_block' => ['required', Rule::in(ChatbotFeedback::TARGETS)],
            'proposed_text' => ['required', 'string', 'max:'.config('chatbots.instructions_max')],
        ], [], ['reason' => 'el motivo', 'proposed_text' => 'el contenido propuesto']);

        $this->feedback->propose($request->user(), $conversationModel, $messageModel, $data);

        return back()->with('success', 'Propuesta enviada. No cambia nada hasta que una persona autorizada la apruebe en IA y comportamiento del asistente.');
    }
}
