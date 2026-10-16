<?php

namespace App\Http\Controllers;

use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\BookingPresenter;
use App\Booking\PublicBooking;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The open booking page and its endpoints (no session). The public key in the URL names one Workspace and nothing else is chosen by
 * the visitor; who they are is only what they type, and a booking is managed with the unguessable link returned when it is made.
 * Every answer is limited to what the visitor needs: no contact data of anyone, no internal ids of other Workspaces.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly PublicBooking $public,
        private readonly BookingManager $bookings,
        private readonly BookingPresenter $present,
    ) {}

    /** The booking page itself (React); it reads everything else from the endpoints below. */
    public function page(string $key): Response
    {
        $workspace = $this->public->resolve($key);

        return Inertia::render('Booking/Public', ['publicKey' => $key, 'business' => $workspace->name, 'primaryColor' => $workspace->settingsOrDefault()->primary_color]);
    }

    public function config(string $key): JsonResponse
    {
        $workspace = $this->public->resolve($key);

        return $this->json($this->public->config($workspace, $this->bookings));
    }

    public function services(Request $request, string $key): JsonResponse
    {
        return $this->guard(fn (Workspace $workspace) => ['services' => array_map(fn (array $service) => [
            'id' => $service['id'], 'name' => $service['name'], 'description' => $service['description'], 'durationMinutes' => $service['duration_minutes'], 'price' => $service['price'], 'category' => $service['category'],
        ], $this->bookings->bookableServices($workspace, $this->locationQuery($request)))], $key);
    }

    public function professionals(Request $request, string $key): JsonResponse
    {
        return $this->guard(fn (Workspace $workspace) => ['professionals' => array_map(fn (array $item) => ['id' => $item['id'], 'name' => $item['name'], 'specialty' => $item['specialty']], $this->bookings->professionals($workspace, $request->query('service_id'), $this->locationQuery($request)))], $key);
    }

    public function availability(AvailabilityRequest $request, string $key): JsonResponse
    {
        return $this->guard(fn (Workspace $workspace) => ['slots' => array_map(
            fn (array $slot) => $this->present->slot($workspace, $slot),
            // With the customer's link, the time that booking holds counts as free for it (so it can be moved a little).
            $this->bookings->availability($workspace, $request->validated('service_id'), $request->validated('professional_id') ?: null, $request->from($workspace->settingsOrDefault()->timezone), $request->days(), $request->validated('token') ? $this->bookings->findByToken($workspace, $request->validated('token')) : null, $request->locationId()),
        )], $key);
    }

    public function book(BookAppointmentRequest $request, string $key): JsonResponse
    {
        return $this->guard(function (Workspace $workspace) use ($request) {
            $result = $this->bookings->request($workspace, $request->booking(), 'web');

            return ['appointment' => $this->present->publicAppointment($result['appointment']->load('workspace')), 'token' => $result['token']];
        }, $key, 201);
    }

    public function show(string $key, string $token): JsonResponse
    {
        return $this->guard(fn (Workspace $workspace) => ['appointment' => $this->present->publicAppointment($this->bookings->findByToken($workspace, $token))], $key);
    }

    /** The customer, with their own link, says they will come. Same rules as every channel: only an open future appointment, never a revival. */
    public function confirm(string $key, string $token): JsonResponse
    {
        return $this->guard(function (Workspace $workspace) use ($token) {
            $result = $this->bookings->customerConfirm($this->bookings->findByToken($workspace, $token), 'link');

            return ['appointment' => $this->present->publicAppointment($result['appointment']->load('workspace')), 'replayed' => $result['replayed']];
        }, $key);
    }

    public function cancel(string $key, string $token): JsonResponse
    {
        return $this->guard(function (Workspace $workspace) use ($token) {
            $appointment = $this->bookings->cancel($this->bookings->findByToken($workspace, $token), null, 'web');

            return ['appointment' => $this->present->publicAppointment($appointment)];
        }, $key);
    }

    public function reschedule(RescheduleAppointmentRequest $request, string $key, string $token): JsonResponse
    {
        return $this->guard(function (Workspace $workspace) use ($request, $token) {
            $appointment = $this->bookings->reschedule($this->bookings->findByToken($workspace, $token), $request->startsAt(), $request->professionalId(), 'web');

            return ['appointment' => $this->present->publicAppointment($appointment)];
        }, $key);
    }

    /** Resolves the Workspace from the key, runs the action and answers; a refused operation is a JSON error with a fixed `reason`. */
    private function guard(callable $action, string $key, int $status = 200): JsonResponse
    {
        $workspace = $this->public->resolve($key);

        try {
            return $this->json($action($workspace), $status);
        } catch (BookingException $exception) {
            return $this->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->status());
        }
    }

    /** The location the visitor narrowed the list to, if any: only digits count, anything else is the same as none (Locations refuses an unknown one). */
    private function locationQuery(Request $request): ?string
    {
        $value = $request->query('location_id');

        return is_string($value) && ctype_digit($value) ? $value : null;
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }
}
