<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMessageTemplateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Plantillas of Campañas: reusable messages of the active Workspace (never deleted, only deactivated). The page is Campaigns/Index. */
class MessageTemplateController extends Controller
{
    public function store(SaveMessageTemplateRequest $request): RedirectResponse
    {
        $request->attributes->get('workspace')->messageTemplates()->create($request->template());

        return redirect()->route('campaigns.index', ['tab' => 'templates'])->with('success', 'Plantilla creada correctamente');
    }

    public function update(SaveMessageTemplateRequest $request, int $template): RedirectResponse
    {
        $request->attributes->get('workspace')->messageTemplates()->findOrFail($template)->update($request->template());

        return back()->with('success', 'Plantilla actualizada correctamente');
    }

    public function activate(Request $request, int $template): RedirectResponse
    {
        return $this->toggle($request, $template, true);
    }

    public function deactivate(Request $request, int $template): RedirectResponse
    {
        return $this->toggle($request, $template, false);
    }

    private function toggle(Request $request, int $template, bool $active): RedirectResponse
    {
        $request->attributes->get('workspace')->messageTemplates()->findOrFail($template)->update(['is_active' => $active]);

        return back()->with('success', $active ? 'Plantilla activada correctamente' : 'Plantilla desactivada correctamente');
    }
}
