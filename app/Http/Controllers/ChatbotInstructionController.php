<?php

namespace App\Http\Controllers;

use App\Models\Chatbot;
use App\Services\ChatbotFeedback;
use App\Services\ChatbotInstructions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The instructions of an assistant, block by block, and the review of the proposals made from conversations. Every route needs `edit-chatbots` and the module Asistentes,
 * and starts from the Workspace of the session (`$workspace->chatbots()`), so an assistant of another Workspace is a 404.
 */
class ChatbotInstructionController extends Controller
{
    public function __construct(private readonly ChatbotInstructions $instructions, private readonly ChatbotFeedback $feedback) {}

    public function update(Request $request, int $chatbot, string $block): RedirectResponse
    {
        abort_unless(ChatbotInstructions::ready() && isset(ChatbotInstructions::BLOCKS[$block]), 404);

        $data = $request->validate([
            // `present`: a request that does not carry the field at all is refused instead of being read as «empty the block».
            'text' => ['present', 'nullable', 'string', 'max:'.config('chatbots.instructions_max')],
            'expected_hash' => ['required', 'string', 'size:40'],
        ], [], ['text' => 'el texto']);

        $model = $this->find($request, $chatbot);
        $before = (int) $model->instructions_version;
        $version = $this->instructions->save($model, $block, $data['text'] ?? null, $data['expected_hash'], $request->user());

        return back()->with('success', $version === $before
            ? 'No había cambios que guardar.'
            : "Apartado guardado en Zonno (versión {$version}). n8n lo recibirá en su próxima lectura; verás «Sincronizado» cuando lo confirme.");
    }

    public function mode(Request $request, int $chatbot): RedirectResponse
    {
        abort_unless(ChatbotInstructions::ready(), 404);

        $data = $request->validate(['mode' => ['required', 'string', Rule::in(array_keys(ChatbotInstructions::MODES))]]);
        $model = $this->find($request, $chatbot);
        $before = (int) $model->instructions_version;
        $version = $this->instructions->setMode($model, $data['mode'], $request->user());

        return back()->with('success', $version === $before
            ? 'El asistente ya usaba ese modo.'
            : 'Modo '.($data['mode'] === 'single' ? 'Single' : 'Modular')." activado (versión {$version}). El contenido del otro modo se conserva; n8n recibirá el nuevo texto en su próxima lectura.");
    }

    public function approve(Request $request, int $chatbot, int $feedback): RedirectResponse
    {
        $version = $this->feedback->approve($request->user(), $this->find($request, $chatbot), $feedback);

        return back()->with('success', "Cambio aprobado y guardado en Zonno (versión {$version}). Queda pendiente de que n8n lo confirme.");
    }

    public function reject(Request $request, int $chatbot, int $feedback): RedirectResponse
    {
        $this->feedback->reject($request->user(), $this->find($request, $chatbot), $feedback);

        return back()->with('success', 'Propuesta rechazada. Las instrucciones no cambiaron.');
    }

    private function find(Request $request, int $chatbot): Chatbot
    {
        return $request->attributes->get('workspace')->chatbots()->findOrFail($chatbot);
    }
}
