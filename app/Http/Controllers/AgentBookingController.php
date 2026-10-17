<?php

namespace App\Http\Controllers;

use App\Booking\AppointmentNotifier;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\BookingPresenter;
use App\Booking\Locations;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Services\AgentConversationContext;
use App\Services\AgentToolTrace;
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
    public function __construct(private readonly BookingManager $bookings, private readonly BookingPresenter $present, private readonly Locations $locations, private readonly AgentConversationContext $context, private readonly AgentToolTrace $trace) {}

    public function services(Request $request): JsonResponse
    {
        $location = ctype_digit((string) $request->query('location_id')) ? $request->query('location_id') : null;

        return $this->run(fn (Workspace $workspace) => ['services' => $this->bookings->bookableServices($workspace, $location)] + $this->capabilities($workspace));
    }

    /** The active locations of the Workspace (empty when none applies): with several, a booking must name one by id. */
    public function locations(Request $request): JsonResponse
    {
        return $this->run(fn (Workspace $workspace) => [
            'locations' => $this->locations->active($workspace)->map(fn ($location) => ['id' => $location->id, 'name' => $location->name, 'address' => $location->address, 'city' => $location->city, 'phone' => $location->phone, 'notes' => $location->notes])->values()->all(),
            'required' => $this->locations->required($workspace),
        ]);
    }

    public function professionals(Request $request): JsonResponse
    {
        return $this->run(fn (Workspace $workspace) => ['professionals' => $this->bookings->professionals($workspace, $request->query('service_id'), ctype_digit((string) $request->query('location_id')) ? $request->query('location_id') : null)]);
    }

    public function availability(AvailabilityRequest $request): JsonResponse
    {
        // To move an appointment the automation names it (and whose conversation it is): its own time then counts as free.
        $moving = $request->validated('ignore_appointment') ? $this->contact($request) : null;

        return $this->run(fn (Workspace $workspace) => ['slots' => array_map(
            fn (array $slot) => $this->present->slot($workspace, $slot),
            $this->bookings->availability($workspace, $request->validated('service_id'), $request->validated('professional_id') ?: null, $request->from($workspace->settingsOrDefault()->timezone), $request->days(), $moving ? $this->owned($request, (int) $request->validated('ignore_appointment'), $moving) : null, $request->locationId()),
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

            $this->traced($request, $result['appointment'], $result['replayed'] ?? false);

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

            return ['appointments' => $workspace->appointments()->with(['customer', 'workspace'])->when(app(AppointmentNotifier::class)->ready(), fn ($query) => $query->with('notifications'))->where('conversation_id', $conversation->id)->whereIn('status', Appointment::BLOCKING)->orderBy('starts_at')->get()->map(fn (Appointment $appointment) => $this->answer($appointment))->all()];
        });
    }

    public function cancel(Request $request, int $appointment): JsonResponse
    {
        $data = $this->contact($request);

        return $this->run(function (Workspace $workspace) use ($request, $appointment, $data) {
            $cancelled = $this->bookings->cancel($this->owned($request, $appointment, $data), null, 'chat');
            $this->traced($request, $cancelled);

            return ['appointment' => $this->answer($cancelled)];
        });
    }

    /**
     * The contact answered that they will come. Only for an appointment of THIS conversation. It records the customer's answer and nothing else:
     * it never confirms a booking the business has not accepted (`status` stays what the backend says) and an appointment that was cancelled,
     * closed or has started is refused. Asking twice is the same answer (`replayed`).
     */
    public function confirm(Request $request, int $appointment): JsonResponse
    {
        $data = $this->contact($request);

        return $this->run(function (Workspace $workspace) use ($request, $appointment, $data) {
            $result = $this->bookings->customerConfirm($this->owned($request, $appointment, $data), 'assistant');

            $this->traced($request, $result['appointment'], $result['replayed']);

            return ['appointment' => $this->answer($result['appointment']->load(['customer', 'workspace'])), 'replayed' => $result['replayed']];
        });
    }

    public function reschedule(RescheduleAppointmentRequest $request, int $appointment): JsonResponse
    {
        $data = $this->contact($request);

        return $this->run(function (Workspace $workspace) use ($request, $appointment, $data) {
            $moved = $this->bookings->reschedule($this->owned($request, $appointment, $data), $request->startsAt(), $request->professionalId(), 'chat');
            $this->traced($request, $moved);

            return ['appointment' => $this->answer($moved)];
        });
    }

    /**
     * What the trace may say about an appointment the tool changed, set only now that the change exists: which appointment, whether this was a retry, the state Ava
     * (or the agenda) really gave it, and whether messages to the customer are PLANNED (never that they were sent: sending is recorded in appointment_notifications).
     */
    private function traced(Request $request, Appointment $appointment, bool $replayed = false): void
    {
        $planned = app(AppointmentNotifier::class)->ready() && $appointment->notifications()->exists();
        $this->trace->report(['resource_type' => 'appointment', 'resource_id' => $appointment->id, 'replayed' => $replayed, 'result_state' => $appointment->status, 'notification' => $planned ? 'planned' : 'none']);
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
        return $this->context->contact($request);
    }

    /** The conversation of THIS chatbot with that contact; without it there is nothing to attach a booking to. */
    private function conversation(Request $request, array $data): Conversation
    {
        return $this->context->conversation($request, $data)
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
        // What the automation may know about the messages Ava sent: kind, state and time. No text, no phone, no reason.
        $messages = $appointment->relationLoaded('notifications') ? array_map(fn (array $n) => array_intersect_key($n, array_flip(['kind', 'offset', 'status', 'statusLabel', 'scheduledFor'])), app(AppointmentNotifier::class)->summary($appointment, false)) : [];

        return ['notifications' => $messages] + array_intersect_key($this->present->appointment($appointment), array_flip(['id', 'service', 'professional', 'location', 'price', 'currency', 'startsAt', 'dateLabel', 'timeLabel', 'status', 'statusLabel', 'syncStatus', 'customerConfirmed']));
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
