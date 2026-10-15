<?php

namespace App\Http\Controllers;

use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\BookingPresenter;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The booking tools of Ava Chat, for the automation (n8n) that runs a chatbot. n8n executes the operation the contact asked for
 * and hands the REAL result back to Ava's conversation; it never owns the booking. The chatbot and its Workspace come from the token
 * (see AuthenticateAgent), the conversation is the one of this chatbot with `channel` + `contact_id`, and a contact can only see and
 * change the appointments made from that conversation. Everything runs through BookingManager, so it follows the Workspace's agenda
 * and its rules, and what the agenda did not confirm is never reported as confirmed.
 */
class AgentBookingController extends Controller
{
    public function __construct(private readonly BookingManager $bookings, private readonly BookingPresenter $present) {}

    public function services(Request $request): JsonResponse
    {
        return $this->run(fn (Workspace $workspace) => ['services' => $this->bookings->bookableServices($workspace)] + $this->capabilities($workspace));
    }

    public function professionals(Request $request): JsonResponse
    {
        return $this->run(fn (Workspace $workspace) => ['professionals' => $this->bookings->professionals($workspace, $request->query('service_id'))]);
    }

    public function availability(AvailabilityRequest $request): JsonResponse
    {
        // To move an appointment the automation names it (and whose conversation it is): its own time then counts as free.
        $moving = $request->validated('ignore_appointment') ? $this->contact($request) : null;

        return $this->run(fn (Workspace $workspace) => ['slots' => array_map(
            fn (array $slot) => $this->present->slot($workspace, $slot),
            $this->bookings->availability($workspace, $request->validated('service_id'), $request->validated('professional_id') ?: null, $request->from($workspace->settingsOrDefault()->timezone), $request->days(), $moving ? $this->owned($request, (int) $request->validated('ignore_appointment'), $moving) : null),
        )]);
    }

    public function book(BookAppointmentRequest $request): JsonResponse
    {
        $data = $this->contact($request);

        $status = 201;

        return $this->run(function (Workspace $workspace) use ($request, $data, &$status) {
            $conversation = $this->conversation($request, $data);
            $result = $this->bookings->request($workspace, $request->booking(), 'chat', $conversation->id);
            // The same request again returns the booking Ava already has (200), not a second one.
            $status = ($result['replayed'] ?? false) ? 200 : 201;

            return ['appointment' => $this->answer($result['appointment']), 'confirmed' => $result['appointment']->status === Appointment::CONFIRMED, 'replayed' => $result['replayed'] ?? false];
        }, function () use (&$status) {
            return $status;
        });
    }

    /** The active appointments made from this conversation. */
    public function index(Request $request): JsonResponse
    {
        $data = $this->contact($request);

        return $this->run(function (Workspace $workspace) use ($request, $data) {
            $conversation = $this->conversation($request, $data);

            return ['appointments' => $workspace->appointments()->with(['customer', 'workspace'])->where('conversation_id', $conversation->id)->whereIn('status', Appointment::BLOCKING)->orderBy('starts_at')->get()->map(fn (Appointment $appointment) => $this->answer($appointment))->all()];
        });
    }

    public function cancel(Request $request, int $appointment): JsonResponse
    {
        $data = $this->contact($request);

        return $this->run(fn (Workspace $workspace) => ['appointment' => $this->answer($this->bookings->cancel($this->owned($request, $appointment, $data), null, 'chat'))]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, int $appointment): JsonResponse
    {
        $data = $this->contact($request);

        return $this->run(fn (Workspace $workspace) => ['appointment' => $this->answer($this->bookings->reschedule($this->owned($request, $appointment, $data), $request->startsAt(), $request->professionalId(), 'chat'))]);
    }

    /** What the automation may do with this Workspace's agenda right now, so it can tell the contact instead of failing. @return array<string, mixed> */
    private function capabilities(Workspace $workspace): array
    {
        $capabilities = $this->bookings->capabilities($workspace);

        return ['capabilities' => ['availability' => $capabilities['availability'] && $capabilities['connected'], 'book' => $capabilities['book'] && $capabilities['connected'], 'reschedule' => $capabilities['reschedule'], 'cancel' => $capabilities['cancel']]];
    }

    /** @return array{channel: string, contact_id: string} who the automation is talking to: validated, never trusted as an id of ours */
    private function contact(Request $request): array
    {
        // A run-scoped token already says whose conversation this is: the caller cannot name another one.
        if ($execution = $request->attributes->get('execution')) {
            $conversation = $execution->conversation;
            abort_if(($request->input('channel') !== null && $request->input('channel') !== $conversation->channel)
                || ($request->input('contact_id') !== null && (string) $request->input('contact_id') !== $conversation->contact_id), 403, 'Este token solo puede actuar en la conversación de su ejecución.');

            return ['channel' => $conversation->channel, 'contact_id' => $conversation->contact_id];
        }

        return $request->validate(['channel' => ['required', 'string', 'max:32'], 'contact_id' => ['required', 'string', 'max:64']]);
    }

    /** The conversation of THIS chatbot with that contact; without it there is nothing to attach a booking to. */
    private function conversation(Request $request, array $data): Conversation
    {
        return $request->attributes->get('chatbot')->conversations()->where('channel', $data['channel'])->where('contact_id', $data['contact_id'])->first()
            ?? throw new BookingException(BookingException::NOT_FOUND, 'No existe una conversación de este asistente con ese contacto. Reporta primero su mensaje a Ava.');
    }

    private function owned(Request $request, int $id, array $data): Appointment
    {
        $conversation = $this->conversation($request, $data);

        return $request->attributes->get('workspace')->appointments()->with(['customer', 'workspace'])->where('conversation_id', $conversation->id)->find($id)
            ?? throw new BookingException(BookingException::NOT_FOUND, 'No encontramos esa cita en esta conversación.');
    }

    /** The result as the automation tells the contact: the status Ava (or the agenda) really gave it. @return array<string, mixed> */
    private function answer(Appointment $appointment): array
    {
        return array_intersect_key($this->present->appointment($appointment), array_flip(['id', 'service', 'professional', 'startsAt', 'dateLabel', 'timeLabel', 'status', 'statusLabel', 'syncStatus']));
    }

    private function run(callable $action, int|\Closure $status = 200): JsonResponse
    {
        try {
            $body = $action(request()->attributes->get('workspace'));

            return response()->json($body, is_int($status) ? $status : $status())->header('Cache-Control', 'no-store');
        } catch (BookingException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason] + ($exception->appointmentId ? ['appointment_id' => $exception->appointmentId] : []), $exception->status());
        }
    }
}
