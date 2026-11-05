<?php

namespace App\Booking;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentReply;
use App\Models\Chatbot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer answers with the three buttons of a WhatsApp reminder (Confirmar, Reprogramar, Cancelar). Meta returns the `payload` Ava put on each button when it sent the
 * template, so the payload is the ONLY thing that identifies the appointment, and it is trusted only as far as it can be checked:
 *  - it carries Ava's own notification id and an HMAC with the application key (a forged or edited payload is ignored, nothing is changed);
 *  - the Workspace comes from the integration that received the event (the chatbot), never from the payload: a notification of another Workspace is simply not found;
 *  - the sender's number must be the number the reminder went to (the card's, or the one the appointment was booked with);
 *  - the notification must belong to the CURRENT version of the appointment (a moved appointment makes older buttons expire), the appointment must still be active and not started;
 *  - Meta's message id is stored (`appointment_replies`, unique per Workspace), so a retried webhook or a second tap of the same message applies nothing twice.
 * Confirmar and Cancelar use the same operations as the rest of Agenda. Reprogramar changes NOTHING in the agenda: it records the intention and hands the conversation to the AI
 * (the caller starts the workflow), which moves the appointment with the booking tools under the usual availability rules; the appointment is tied to the conversation (when it has
 * none) so those tools can see it.
 */
class AppointmentReplies
{
    private const PREFIX = 'avr';

    private const CODES = ['confirm' => 'c', 'reschedule' => 'r', 'cancel' => 'x'];

    /** The payloads of the three buttons, in the order they are shown: Confirmar, Reprogramar, Cancelar. @return list<string> */
    public static function payloads(AppointmentNotification $notification): array
    {
        return array_map(fn (string $action) => self::payload($notification->id, $action), AppointmentReply::ACTIONS);
    }

    public static function payload(int $notificationId, string $action): string
    {
        return self::PREFIX.':'.self::CODES[$action].':'.$notificationId.':'.self::signature($notificationId, $action);
    }

    /** @return array{action: string, notification: int}|null null when it is not one of Ava's payloads or its signature does not match */
    public static function parse(string $payload): ?array
    {
        if (! preg_match('/^'.self::PREFIX.':([crx]):(\d{1,18}):([a-f0-9]{24})$/', $payload, $match)) {
            return null;
        }

        $action = array_search($match[1], self::CODES, true);

        return $action !== false && hash_equals(self::signature((int) $match[2], $action), $match[3]) ? ['action' => $action, 'notification' => (int) $match[2]] : null;
    }

    public static function isOurs(?string $payload): bool
    {
        return is_string($payload) && self::parse($payload) !== null;
    }

    private static function signature(int $notificationId, string $action): string
    {
        return substr(hash_hmac('sha256', "{$notificationId}:{$action}", (string) config('app.key')), 0, 24);
    }

    /**
     * Applies one answer. Returns null when the event is not a valid answer of this Workspace (nothing was changed or recorded) or when it was already applied (a repeated event).
     *
     * @return array{action: string, result: string, appointment: Appointment}|null
     */
    public function handle(Chatbot $chatbot, string $from, string $externalId, string $payload, ?int $conversationId = null): ?array
    {
        $parsed = self::parse($payload);

        if ($parsed === null || ! Schema::hasTable('appointment_replies')) {
            return null;
        }

        $notification = AppointmentNotification::where('workspace_id', $chatbot->workspace_id)->find($parsed['notification']);
        $appointment = $notification ? Appointment::where('workspace_id', $chatbot->workspace_id)->with(['customer.card', 'workspace'])->find($notification->appointment_id) : null;

        if (! $notification || ! $appointment) {
            Log::info('appointment_reply_ignored', ['reason' => 'unknown_notification']);

            return null;
        }

        $result = DB::transaction(function () use ($chatbot, $from, $externalId, $parsed, $notification, $appointment, $conversationId) {
            // A repeated event (Meta retries, a second tap on the same message) finds its row and stops here.
            if (AppointmentReply::where('workspace_id', $chatbot->workspace_id)->where('external_id', $externalId)->exists()) {
                return null;
            }

            $current = Appointment::where('workspace_id', $chatbot->workspace_id)->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $result = $this->decide($current, $notification, $from, $parsed['action'], $conversationId);

            AppointmentReply::create(['workspace_id' => $chatbot->workspace_id, 'appointment_id' => $current->id, 'notification_id' => $notification->id, 'action' => $parsed['action'], 'result' => $result, 'channel' => 'whatsapp', 'external_id' => $externalId, 'responded_at' => now()]);

            return ['action' => $parsed['action'], 'result' => $result, 'appointment' => $current->fresh()];
        });

        return $result;
    }

    private function decide(Appointment $appointment, AppointmentNotification $notification, string $from, string $action, ?int $conversationId): string
    {
        $digits = app(AppointmentNotifier::class)->recipientPhone($appointment)[0];

        return match (true) {
            $digits === null || $digits !== preg_replace('/\D+/', '', $from) => AppointmentReply::NOT_AUTHORIZED,
            ! $appointment->isActive() => AppointmentReply::CLOSED,
            (int) $notification->version !== (int) $appointment->reschedule_count || $appointment->starts_at <= now() => AppointmentReply::EXPIRED,
            default => $this->apply($appointment, $action, $conversationId),
        };
    }

    private function apply(Appointment $appointment, string $action, ?int $conversationId): string
    {
        $service = app(AppointmentService::class);

        try {
            return match ($action) {
                'confirm' => $service->customerConfirm($appointment, 'whatsapp')['replayed'] ? AppointmentReply::ALREADY_DONE : AppointmentReply::APPLIED,
                'cancel' => $this->cancel($service, $appointment),
                'reschedule' => $this->requestReschedule($appointment, $conversationId),
            };
        } catch (BookingException) {
            return AppointmentReply::CLOSED;
        }
    }

    private function cancel(AppointmentService $service, Appointment $appointment): string
    {
        $service->cancel($appointment, 'Cancelada por el cliente desde el recordatorio de WhatsApp.', 'whatsapp');

        return AppointmentReply::APPLIED;
    }

    /** The AI can only see appointments of the conversation: an appointment booked by the team (no conversation yet) is tied to the one the customer just wrote in. */
    private function requestReschedule(Appointment $appointment, ?int $conversationId): string
    {
        if ($conversationId !== null && $appointment->conversation_id === null) {
            $appointment->forceFill(['conversation_id' => $conversationId])->save();
        }

        return AppointmentReply::APPLIED;
    }

    /** The acknowledgement sent to the customer for an answer, or null when none is due (a reschedule goes to the AI; a sender who is not the customer gets nothing). */
    public function acknowledgement(string $action, string $result, Appointment $appointment): ?string
    {
        $settings = $appointment->workspace->settingsOrDefault();
        $what = "tu cita de {$appointment->service_name} del ".$settings->formatDate($appointment->starts_at).' a las '.$settings->formatTime($appointment->starts_at);

        return match (true) {
            $result === AppointmentReply::NOT_AUTHORIZED, $action === 'reschedule' => null,
            $result === AppointmentReply::EXPIRED => 'Este botón ya no es válido porque la cita cambió o ya comenzó. Escríbenos si necesitas ayuda.',
            $result === AppointmentReply::CLOSED => 'Esa cita ya no está activa. Escríbenos si quieres reservar de nuevo.',
            $action === 'confirm' && $result === AppointmentReply::ALREADY_DONE => "Ya teníamos confirmada {$what}. ¡Te esperamos!",
            $action === 'confirm' => "¡Gracias! Quedó confirmada {$what}. ¡Te esperamos!",
            default => "Listo, cancelamos {$what}. Si quieres reservar otra, escríbenos por aquí.",
        };
    }

    /** The sentence stored in the conversation for a button, so the history (and the workflow) reads what the customer chose. */
    public function conversationText(string $action, Appointment $appointment): string
    {
        $settings = $appointment->workspace->settingsOrDefault();
        $when = $settings->formatDate($appointment->starts_at).' a las '.$settings->formatTime($appointment->starts_at);

        return match ($action) {
            'confirm' => "Confirmo mi cita de {$appointment->service_name} del {$when}.",
            'cancel' => "Cancelo mi cita de {$appointment->service_name} del {$when}.",
            default => "Quiero reprogramar mi cita de {$appointment->service_name} del {$when}.",
        };
    }
}
