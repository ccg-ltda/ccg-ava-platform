<?php

namespace App\Http\Controllers;

use App\Models\Chatbot;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The documents of the knowledge base, managed from the page of an assistant. Every route needs `edit-chatbots` and the module Asistentes, and starts from the
 * Workspace of the session: an assistant or a document of another Workspace is a 404.
 */
class KnowledgeController extends Controller
{
    public function __construct(private readonly KnowledgeBase $knowledge) {}

    public function store(Request $request, int $chatbot): RedirectResponse
    {
        abort_unless($this->knowledge->ready(), 404);

        $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:160'],
        ], ['file.required' => 'Elige un archivo.', 'file.file' => 'Elige un archivo válido.'], ['file' => 'el archivo']);

        $assistant = $this->assistant($request, $chatbot);
        $document = $this->knowledge->upload($request->attributes->get('workspace'), $request->user(), $request->file('file'), $request->input('title'));
        $this->knowledge->assign($document, $document->chatbots()->pluck('chatbots.id')->push($assistant->id)->all());

        return back()->with($document->status === KnowledgeDocument::READY ? 'success' : 'error', $document->status === KnowledgeDocument::READY
            ? "«{$document->title}» está lista: {$document->chunks_count} fragmentos disponibles para este asistente."
            : "«{$document->title}» se guardó, pero no se pudo leer: ".(KnowledgeBase::ERRORS[$document->error_code] ?? 'error desconocido'));
    }

    public function link(Request $request, int $chatbot, int $document): RedirectResponse
    {
        abort_unless($this->knowledge->ready(), 404);

        $data = $request->validate(['linked' => ['required', 'boolean']]);
        $assistant = $this->assistant($request, $chatbot);
        $model = $this->document($request, $document);
        $ids = $model->chatbots()->pluck('chatbots.id')->reject(fn ($id) => $id === $assistant->id)->values();

        $this->knowledge->assign($model, ($data['linked'] ? $ids->push($assistant->id) : $ids)->all());

        return back()->with('success', $data['linked'] ? 'El asistente podrá consultar este documento.' : 'El asistente ya no consultará este documento.');
    }

    public function retry(Request $request, int $chatbot, int $document): RedirectResponse
    {
        abort_unless($this->knowledge->ready(), 404);
        $this->assistant($request, $chatbot);

        $result = $this->knowledge->retry($this->document($request, $document));

        return back()->with($result->status === KnowledgeDocument::READY ? 'success' : 'error', $result->status === KnowledgeDocument::READY ? 'El documento se procesó correctamente.' : (KnowledgeBase::ERRORS[$result->error_code] ?? 'No se pudo procesar el documento.'));
    }

    public function destroy(Request $request, int $chatbot, int $document): RedirectResponse
    {
        abort_unless($this->knowledge->ready(), 404);
        $this->assistant($request, $chatbot);

        $this->knowledge->delete($this->document($request, $document));

        return back()->with('success', 'Documento eliminado junto con sus fragmentos. Ya ningún asistente lo consulta.');
    }

    private function assistant(Request $request, int $chatbot): Chatbot
    {
        return $request->attributes->get('workspace')->chatbots()->findOrFail($chatbot);
    }

    private function document(Request $request, int $document): KnowledgeDocument
    {
        return KnowledgeDocument::where('workspace_id', $request->attributes->get('workspace')->id)->findOrFail($document);
    }
}
