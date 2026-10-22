<?php

namespace App\Http\Controllers;

use App\Models\CatalogReminder;
use App\Services\CatalogReminders;
use App\Support\MailAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recordatorios del catálogo: scheduled emails that a member writes for themselves (the recipient is always the creator, resolved on the server). Everything
 * starts from the Workspace of the session: a reminder id of another Workspace is a 404, the Workspace and the creator are never fields of the form, and the
 * state rules live in CatalogReminders. Reading is `view-catalog-reminders`; creating, editing (own), cancelling and retrying (own) are `manage-catalog-reminders`.
 */
class CatalogReminderController extends Controller
{
    /** What a fixed reason code means for a person; any other reason is a short text already sanitized by the service. */
    private const REASONS = [
        'creator_inactive' => 'La cuenta de quien lo creó está desactivada.',
        'creator_not_member' => 'Quien lo creó ya no es miembro de este Workspace.',
        'module_unavailable' => 'El plan de este Workspace ya no incluye el Catálogo.',
        'expired' => 'Pasó demasiado tiempo desde la hora programada (el envío o la cola estuvieron detenidos): no se envió para no avisar tarde.',
        'mail_not_configured' => 'El correo saliente no está configurado en este entorno.',
    ];

    public function __construct(private readonly CatalogReminders $reminders) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $timezone = $workspace->settingsOrDefault()->timezone;
        $permissions = $request->attributes->get('workspace_permissions', []);
        $ready = $this->reminders->ready();

        return Inertia::render('Catalog/Reminders', [
            'schemaPending' => ! $ready,
            'timezone' => $timezone,
            'mailReady' => MailAvailability::usable(),
            'me' => $request->user()->id,
            'canManage' => in_array('manage-catalog-reminders', $permissions, true),
            'limits' => ['subject' => (int) config('catalog_reminders.subject_max'), 'body' => (int) config('catalog_reminders.body_max')],
            'reminders' => ! $ready ? [] : $workspace->catalogReminders()->with(['creator:id,name', 'canceller:id,name'])->orderByDesc('scheduled_at')->limit(200)->get()->map(fn (CatalogReminder $reminder) => [
                'id' => $reminder->id,
                'subject' => $reminder->subject,
                'body' => $reminder->body,
                'scheduledAt' => $reminder->scheduled_at->toIso8601String(),
                'scheduledLocal' => $reminder->scheduled_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'status' => $reminder->status,
                'statusLabel' => CatalogReminder::LABELS[$reminder->status],
                'creator' => $reminder->creator?->name,
                'mine' => (int) $reminder->created_by === (int) $request->user()->id,
                'attempts' => $reminder->attempts,
                'sentAt' => $reminder->sent_at?->toIso8601String(),
                'cancelledAt' => $reminder->cancelled_at?->toIso8601String(),
                'cancelledBy' => $reminder->canceller?->name,
                'reason' => $reminder->reason ? (self::REASONS[$reminder->reason] ?? $reminder->reason) : null,
                'nextAttemptAt' => $reminder->next_attempt_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertReady();
        $this->reminders->create($request->attributes->get('workspace'), $request->user(), $request->only(['subject', 'body', 'scheduled_at']));

        return back()->with('success', 'Recordatorio programado correctamente');
    }

    public function update(Request $request, int $reminder): RedirectResponse
    {
        $this->assertReady();
        $this->reminders->update($this->find($request, $reminder), $request->user(), $request->only(['subject', 'body', 'scheduled_at']));

        return back()->with('success', 'Recordatorio actualizado correctamente');
    }

    public function cancel(Request $request, int $reminder): RedirectResponse
    {
        $this->assertReady();
        $this->reminders->cancel($this->find($request, $reminder), $request->user());

        return back()->with('success', 'Recordatorio cancelado');
    }

    public function retry(Request $request, int $reminder): RedirectResponse
    {
        $this->assertReady();
        $this->reminders->retry($this->find($request, $reminder), $request->user());

        return back()->with('success', 'Recordatorio reprogramado para enviarse ahora');
    }

    private function find(Request $request, int $id): CatalogReminder
    {
        return $request->attributes->get('workspace')->catalogReminders()->with('workspace')->findOrFail($id);
    }

    private function assertReady(): void
    {
        abort_unless($this->reminders->ready(), 409, 'Falta aplicar la migración de los recordatorios del catálogo.');
    }
}
