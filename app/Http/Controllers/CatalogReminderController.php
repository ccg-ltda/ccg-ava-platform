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
 * Recordatorios del catálogo: scheduled emails that a member writes for the members they choose (the recipients are validated on the server against the eligible members of the Workspace; with none chosen, the creator). Everything
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
        'no_valid_recipient' => 'Ninguna de las personas elegidas podía recibirlo en ese momento (cuenta desactivada, ya no es miembro o sin acceso a los recordatorios).',
        'partial_recipients' => 'Algunas personas elegidas no pudieron recibirlo (cuenta desactivada, ya no son miembros o sin acceso).',
        'recipient_inactive' => 'Cuenta desactivada o sin correo.',
        'recipient_not_member' => 'Ya no es miembro de este Workspace.',
        'recipient_no_access' => 'Su rol ya no da acceso a los recordatorios.',
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
            // The people who can be chosen: enabled members of THIS Workspace whose role may read the reminders (the same rule the server validates and the sender re-checks).
            'recipientOptions' => ! $ready ? [] : $this->reminders->eligibleRecipients($workspace)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values()->all(),
            'reminders' => ! $ready ? [] : $workspace->catalogReminders()->with(array_merge(['creator:id,name', 'canceller:id,name'], $this->reminders->recipientsReady() ? ['recipients:users.id,users.name,users.email'] : []))->orderByDesc('scheduled_at')->limit(200)->get()->map(fn (CatalogReminder $reminder) => [
                'id' => $reminder->id,
                'subject' => $reminder->subject,
                'body' => $reminder->body,
                'scheduledAt' => $reminder->scheduled_at->toIso8601String(),
                'scheduledLocal' => $reminder->scheduled_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'status' => $reminder->status,
                'statusLabel' => CatalogReminder::LABELS[$reminder->status],
                'creator' => $reminder->creator?->name,
                // Who it goes to (a reminder from before recipients existed goes to its creator) and, once it left, who it reached and who it could not.
                'recipients' => $this->reminders->recipientsOf($reminder)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'delivered' => isset($user->pivot) && $user->pivot->sent_at !== null, 'problem' => isset($user->pivot) && $user->pivot->skip_reason ? (self::REASONS[$user->pivot->skip_reason] ?? null) : null])->values()->all(),
                'recipientIds' => $this->reminders->recipientsOf($reminder)->pluck('id')->values()->all(),
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
        $this->reminders->create($request->attributes->get('workspace'), $request->user(), $request->only(['subject', 'body', 'scheduled_at', 'recipient_ids']));

        return back()->with('success', 'Recordatorio programado correctamente');
    }

    public function update(Request $request, int $reminder): RedirectResponse
    {
        $this->assertReady();
        $this->reminders->update($this->find($request, $reminder), $request->user(), $request->only(['subject', 'body', 'scheduled_at', 'recipient_ids']));

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
