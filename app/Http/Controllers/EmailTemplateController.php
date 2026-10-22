<?php

namespace App\Http\Controllers;

use App\Services\EmailTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The global HTML template of the platform's emails. Only the platform administration inside the administrative Workspace reaches it
 * (`workspace.platform:view-email-template` / `edit-email-template`, re-checked by the route middleware): a client's administrator gets 403 whatever they send.
 * The template is data: it is validated against a whitelist, never compiled or executed, and the preview renders fictitious data and sends nothing.
 */
class EmailTemplateController extends Controller
{
    public function __construct(private readonly EmailTemplates $templates) {}

    public function update(Request $request): RedirectResponse
    {
        $html = $this->html($request);
        $errors = $this->templates->save($html, $request->user());

        if ($errors !== []) {
            throw ValidationException::withMessages(['html' => $errors]);
        }

        return back()->with('success', 'Plantilla guardada: los correos de la plataforma ya la usan');
    }

    /** The rendered preview of what is in the editor (fictitious data). Nothing is stored and nothing is sent. */
    public function preview(Request $request): JsonResponse
    {
        $html = $this->html($request);
        $errors = $this->templates->validate($html);

        return response()->json(['errors' => $errors, 'html' => $errors === [] ? $this->templates->preview($html) : null])->header('Cache-Control', 'no-store');
    }

    public function restore(Request $request): RedirectResponse
    {
        $this->templates->restore($request->user());

        return back()->with('success', 'Plantilla restaurada a la predeterminada');
    }

    private function html(Request $request): string
    {
        $data = $request->validate(['html' => ['required', 'string', 'max:'.EmailTemplates::MAX_BYTES]], ['html.required' => 'La plantilla no puede estar vacía.', 'html.max' => 'La plantilla es demasiado grande.']);

        return (string) $data['html'];
    }
}
