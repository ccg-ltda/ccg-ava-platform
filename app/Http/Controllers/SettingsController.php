<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Http\Middleware\EnsurePlatformAdministration;
use App\Http\Requests\UpdateWorkspaceSettingsRequest;
use App\Models\Workspace;
use App\Payments\PaymentSettings;
use App\Payments\WompiConfig;
use App\Services\ConversationAutoClose;
use App\Services\EmailTemplates;
use App\Services\ModuleAccess;
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
            // Cierre automático: only for a Workspace whose plan has Conversaciones.
            'autoClose' => ConversationAutoClose::ready() && app(ModuleAccess::class)->allows($request->attributes->get('workspace'), 'conversations') ? $this->autoClose($request->attributes->get('workspace')) : null,
            // Wompi: only the platform administration inside the administrative Workspace gets this (the routes re-check it). Never a secret: only "is it set" and its last characters.
            'wompi' => EnsurePlatformAdministration::allows($request, 'view-payment-settings') ? $this->wompi($request) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function autoClose(Workspace $workspace): array
    {
        $settings = app(ConversationAutoClose::class)->settings($workspace);

        return [
            // Not yet configured = the behavior from before: support sessions close at the default, ordinary conversations never close by themselves.
            'configured' => $settings['configured'],
            'enabled' => $settings['enabled'] ?? $workspace->isAdministrative(),
            'minutes' => $settings['minutes'],
            'min' => ConversationAutoClose::MIN, 'max' => ConversationAutoClose::MAX,
        ];
    }

    public function updateConversations(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'minutes' => ['required', 'integer', 'min:'.ConversationAutoClose::MIN, 'max:'.ConversationAutoClose::MAX],
        ], ['minutes.min' => 'Indica al menos 1 minuto.', 'minutes.max' => 'El máximo es 1440 minutos (24 horas).', 'minutes.integer' => 'Indica los minutos como un número entero.']);
        $workspace = $request->attributes->get('workspace');
        $before = app(ConversationAutoClose::class)->settings($workspace);
        app(ConversationAutoClose::class)->save($workspace, ['enabled' => (bool) $data['enabled'], 'minutes' => (int) $data['minutes']]);

        app(AuditLogger::class)->record('updated', 'settings', $workspace->id, $workspace->name, array_values(array_filter([
            $before['enabled'] !== (bool) $data['enabled'] ? AuditLogger::change('Cierre automático', $before['enabled'] === null ? 'sin configurar' : ($before['enabled'] ? 'activo' : 'desactivado'), $data['enabled'] ? 'activo' : 'desactivado') : null,
            $before['minutes'] !== (int) $data['minutes'] ? AuditLogger::change('Minutos de inactividad', (string) $before['minutes'], (string) $data['minutes']) : null,
        ])));

        return back()->with('success', 'Cierre automático guardado');
    }

    /** @return array<string, mixed> */
    private function wompi(Request $request): array
    {
        $payments = app(PaymentSettings::class);
        $active = $payments->active();

        return [
            'canEdit' => EnsurePlatformAdministration::allows($request, 'edit-payment-settings'),
            'activeEnvironment' => $active?->environment,
            // Without a database row the WOMPI_* environment variables are the fallback; say so instead of showing an empty form as "not configured".
            'source' => app(WompiConfig::class)->source(),
            'environments' => collect(PaymentSettings::ENVIRONMENTS)->mapWithKeys(fn (string $env) => [$env => $payments->present($payments->row($env))])->all(),
            'eventsUrl' => url('/api/webhooks/wompi'),
            // SIGO (invoicing) is a separate integration and is NOT connected: the interface says so.
            'invoicing' => ['provider' => 'SIGO', 'connected' => false],
        ];
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
