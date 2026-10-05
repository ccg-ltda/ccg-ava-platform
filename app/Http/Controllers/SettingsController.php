<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkspaceSettingsRequest;
use App\Services\WorkspaceSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuraciones: preferences of the ACTIVE Workspace (taken from the request context, never from the client).
 * Both routes require the `manage-settings` permission of the user's role in that Workspace.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly WorkspaceSettingsService $settings) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => $this->settings->form($request->attributes->get('workspace')),
            'catalog' => $this->settings->catalog(),
        ]);
    }

    public function update(UpdateWorkspaceSettingsRequest $request): RedirectResponse
    {
        $this->settings->update(
            $request->attributes->get('workspace'),
            $request->validated(),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        return redirect()->back(fallback: route('settings.index'))->with('success', 'Configuración guardada correctamente');
    }
}
