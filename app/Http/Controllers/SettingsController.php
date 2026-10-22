<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsurePlatformAdministration;
use App\Http\Requests\UpdateWorkspaceSettingsRequest;
use App\Services\EmailTemplates;
use App\Services\WorkspaceSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuraciones: preferences of the ACTIVE Workspace (taken from the request context, never from the client).
 * Reading needs `view-settings` and saving `edit-settings` (on top of it) in the user's role in that Workspace.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly WorkspaceSettingsService $settings) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => $this->settings->form($request->attributes->get('workspace')),
            'catalog' => $this->settings->catalog(),
            // Reading the settings (view-settings) does not let anyone change them: the form is offered only with edit-settings.
            'canEdit' => in_array('edit-settings', $request->attributes->get('workspace_permissions', []), true),
            // The global email template: offered (and its data sent) ONLY to the platform administration inside the administrative Workspace. The routes re-check it.
            'emailTemplate' => EnsurePlatformAdministration::allows($request, 'view-email-template') ? app(EmailTemplates::class)->current() + [
                'placeholders' => EmailTemplates::PLACEHOLDERS,
                'canEdit' => EnsurePlatformAdministration::allows($request, 'edit-email-template'),
                'brand' => (string) config('branding.name'),
                'maxBytes' => EmailTemplates::MAX_BYTES,
            ] : null,
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
