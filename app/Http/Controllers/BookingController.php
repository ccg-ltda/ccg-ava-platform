<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\AppointmentNotifier;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\BookingPresenter;
use App\Booking\Locations;
use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\SaveBookingNotificationSettingsRequest;
use App\Http\Requests\SaveBookingSettingsRequest;
use App\Models\Appointment;
use App\Models\BookingCustomer;
use App\Models\BookingSetting;
use App\Models\ScheduleRule;
use App\Models\Workspace;
use App\Services\ListPagination;
use App\Services\LocationScope;
use App\Services\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agenda y reservas: the calendar of appointments, who books, the booking rules and the actions on an appointment. Reading is
 * `view-bookings`; creating, editing and cancelling appointments are `create-bookings`, `edit-bookings` and `cancel-bookings`, and the rules and the public page `manage-booking-settings`. Everything starts from the Workspace of
 * the session and goes through BookingManager, so it works on whichever agenda is the Workspace's source of truth.
 */
class BookingController extends Controller
{
    use HandlesBooking;

    private const STATUS_ACTIONS = [Appointment::CONFIRMED, Appointment::COMPLETED, Appointment::NO_SHOW];

    public function __construct(
        private readonly BookingManager $bookings,
        private readonly BookingPresenter $present,
        private readonly ModuleAccess $modules,
        private readonly AuditLogger $audit,
        private readonly Locations $locations,
        private readonly AppointmentNotifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $timezone = $workspace->settingsOrDefault()->timezone;
        $capabilities = $this->bookings->capabilities($workspace);
        $from = $this->day($request->query('from'), $timezone);
        $days = in_array((int) $request->query('days'), [1, 7, 31], true) ? (int) $request->query('days') : 7;
        [$end, $span] = $this->window($request->query('to'), $from, $days, $timezone);
        $professionalId = ctype_digit((string) $request->query('professional')) ? (int) $request->query('professional') : null;
        $locationId = ctype_digit((string) $request->query('location')) ? (int) $request->query('location') : null;
        $serviceId = ctype_digit((string) $request->query('service')) ? (int) $request->query('service') : null;
        $status = in_array($request->query('status'), Appointment::STATUSES, true) ? $request->query('status') : null;
        $origin = in_array($request->query('origin'), Appointment::CHANNELS, true) ? $request->query('origin') : null;
        $setting = BookingSetting::for($workspace);

        return Inertia::render('Bookings/Index', [
            'capabilities' => $capabilities,
            'filters' => ['from' => $from->setTimezone($timezone)->toDateString(), 'to' => $span['to'], 'days' => $span['days'], 'professional' => $professionalId, 'location' => $locationId, 'service' => $serviceId, 'status' => $status, 'origin' => $origin, 'search' => trim((string) $request->query('search', ''))],
            'appointments' => $workspace->appointments()->with(['customer', 'workspace'])->when($this->notifier->ready(), fn ($query) => $query->with('notifications'))
                ->when(($allowed = app(LocationScope::class)->allowedIds($workspace, $request->user())) !== null, fn ($query) => $query->whereIn('location_id', $allowed))
                ->where('starts_at', '>=', $from)->where('starts_at', '<', $end)
                ->when($professionalId, fn ($query) => $query->where('professional_id', $professionalId))
                // The location filter narrows INSIDE what the member may see: a limited member's own locations always apply first, so a manipulated filter can only
                // return less, never another location's appointments. The service filter is the appointment's own service.
                ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
                ->when($serviceId, fn ($query) => $query->where('service_id', $serviceId))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($origin, fn ($query) => $query->where('channel', $origin))
                ->orderBy('starts_at')->limit(self::LIST_LIMIT)->get()->map(fn (Appointment $appointment) => $this->present->appointment($appointment))->all(),
            'options' => $this->options($workspace, $capabilities, $request),
            'customers' => $this->customers($request, $workspace),
            'settings' => $this->settings($workspace, $setting),
            'perPageOptions' => ListPagination::OPTIONS,
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'listLimit' => self::LIST_LIMIT,
        ]);
    }

    /** Free starts for the new-appointment and reschedule dialogs (staff see the same offer the public sees). */
    public function availability(AvailabilityRequest $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->guardLocation($request, $request->validated('location_id') ? (int) $request->validated('location_id') : null);

        try {
            $ignore = $request->validated('ignore_appointment') ? $workspace->appointments()->findOrFail($request->validated('ignore_appointment')) : null;
            $slots = $this->bookings->availability($workspace, $request->validated('service_id'), $request->validated('professional_id') ?: null, $request->from($workspace->settingsOrDefault()->timezone), $request->days(), $ignore, $request->locationId());
        } catch (BookingException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->status());
        }

        return response()->json(['slots' => array_map(fn (array $slot) => $this->present->slot($workspace, $slot), $slots)])->header('Cache-Control', 'no-store');
    }

    public function store(BookAppointmentRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->guardLocation($request, $request->booking()['location_id']);

        return $this->attempt(fn () => $this->bookings->request($workspace, $request->booking(), 'admin'), 'Cita creada correctamente');
    }

    public function status(Request $request, int $appointment): RedirectResponse
    {
        $model = $this->find($request, $appointment);
        $to = (string) $request->validate(['status' => ['required', 'in:'.implode(',', self::STATUS_ACTIONS)]])['status'];

        return $this->attempt(fn () => $this->bookings->transition($model, $to), 'Estado de la cita actualizado');
    }

    public function reschedule(RescheduleAppointmentRequest $request, int $appointment): RedirectResponse
    {
        $model = $this->find($request, $appointment);

        return $this->attempt(fn () => $this->bookings->reschedule($model, $request->startsAt(), $request->professionalId(), 'admin'), 'Cita reprogramada correctamente');
    }

    public function cancel(Request $request, int $appointment): RedirectResponse
    {
        $model = $this->find($request, $appointment);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return $this->attempt(fn () => $this->bookings->cancel($model, $reason, 'admin'), 'Cita cancelada correctamente');
    }

    /** Somebody of the team registers that the customer said they will come (by phone, in person...). Never changes the booking's status. */
    public function customerConfirmation(Request $request, int $appointment): RedirectResponse
    {
        $model = $this->find($request, $appointment);

        return $this->attempt(fn () => $this->bookings->customerConfirm($model, 'staff'), 'Confirmación del cliente registrada');
    }

    public function refresh(Request $request, int $appointment): RedirectResponse
    {
        $model = $this->find($request, $appointment);

        return $this->attempt(fn () => $this->bookings->refresh($model), 'Estado consultado en la agenda externa');
    }

    public function updateSettings(SaveBookingSettingsRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validated();

        if ($data['provider'] !== 'internal' && ! $this->providerUsable($workspace, $data['provider'])) {
            throw ValidationException::withMessages(['provider' => 'Esa agenda no está disponible: el plan debe incluir Proveedores de agenda y la integración debe estar configurada y activa en Integraciones.']);
        }

        $setting = $workspace->bookingSetting()->updateOrCreate([], $data);

        if ($setting->public_enabled) {
            $setting->ensurePublicKey();
        }

        return back()->with('success', 'Configuración de reservas guardada');
    }

    /** Confirmations and reminders: one shared configuration per Workspace (the same for a clinic and a salon). */
    public function updateNotifications(SaveBookingNotificationSettingsRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        if (! $this->notifier->ready()) {
            return back()->with('error', 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
        }

        $workspace->bookingSetting()->updateOrCreate([], $request->settings());

        return back()->with('success', 'Confirmaciones y recordatorios guardados');
    }

    /**
     * Replaces the public key: the old link (and every booking page or tool using it) stops existing at once. For when the link leaked or
     * is being abused; the bookings already made keep working with the links their customers hold (those are their own).
     */
    public function rotateKey(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $setting = $workspace->bookingSetting()->firstOrCreate([]);
        $setting->forceFill(['public_key' => Str::random(32)])->save();
        $this->audit->record('updated', 'booking_settings', $setting->id, $workspace->name, [AuditLogger::change('Enlace de reservas', null, 'Regenerado')], $workspace);

        return back()->with('success', 'Enlace de reservas regenerado. El anterior ya no funciona.');
    }

    private function find(Request $request, int $id): Appointment
    {
        $appointment = $request->attributes->get('workspace')->appointments()->with(['customer', 'workspace'])->findOrFail($id);

        // An appointment of another location (or of none) is not for a member limited to selected locations, whatever id the request carries.
        app(LocationScope::class)->assertAllows($request->attributes->get('workspace'), $request->user(), $appointment->location_id);

        return $appointment;
    }

    /**
     * A member limited to selected locations needs at least one active authorized location to book or look for free hours, and the one they name must be theirs.
     * (Without this a member with no authorized location would be treated as "the Workspace has no locations" and book without one.)
     */
    private function guardLocation(Request $request, ?int $locationId): void
    {
        $workspace = $request->attributes->get('workspace');
        $scope = app(LocationScope::class);

        if (! $scope->restricted($workspace, $request->user())) {
            return;
        }

        abort_if(app(Locations::class)->active($workspace)->isEmpty(), 403, 'No tienes ninguna sede activa autorizada.');
        abort_if($locationId !== null && ! $scope->allows($workspace, $request->user(), $locationId), 403, 'No tienes acceso a esa sede.');
    }

    /** Appointments the agenda sends for a window; when it is reached the page says the list may be incomplete (narrow the dates or the filters). */
    public const LIST_LIMIT = 500;

    /** Longest range the agenda lists at once (a day in the list is a block of appointments, and the list is capped). */
    private const MAX_RANGE_DAYS = 92;

    /**
     * The end (exclusive) of the listed window and what to report back. Without `to` it is the chosen number of days; with `to` (a local day, inclusive)
     * the window is exactly from..to, in the Workspace's time zone. A `to` before `from`, or an interval longer than the limit, is a validation error
     * (shown next to the field) and nothing is listed from it.
     *
     * @return array{0: CarbonImmutable, 1: array{to: ?string, days: int}}
     *
     * @throws ValidationException
     */
    private function window(mixed $to, CarbonImmutable $from, int $days, string $timezone): array
    {
        if (! is_string($to) || $to === '') {
            return [$from->addDays($days), ['to' => null, 'days' => $days]];
        }

        $parsed = CarbonImmutable::canBeCreatedFromFormat($to, '!Y-m-d') ? CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone) : false;

        if (! $parsed || $parsed->format('Y-m-d') !== $to) {
            throw ValidationException::withMessages(['to' => 'La fecha final no es válida.']);
        }

        $first = $from->setTimezone($timezone)->startOfDay();

        if ($parsed->lt($first)) {
            throw ValidationException::withMessages(['to' => 'La fecha inicial no puede ser posterior a la final.']);
        }

        $span = (int) $first->diffInDays($parsed) + 1;

        if ($span > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Elige un intervalo de máximo '.self::MAX_RANGE_DAYS.' días.']);
        }

        return [$parsed->addDay()->utc(), ['to' => $to, 'days' => $span]];
    }

    private function day(mixed $value, string $timezone): CarbonImmutable
    {
        $parsed = is_string($value) && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? CarbonImmutable::createFromFormat('Y-m-d', $value, $timezone) : false;

        return ($parsed ?: CarbonImmutable::now($timezone))->startOfDay()->utc();
    }

    /** What the new-appointment dialog may choose: the agenda's own services and professionals, or a clear reason they are not available. @return array<string, mixed> */
    private function options(Workspace $workspace, array $capabilities, ?Request $request = null): array
    {
        try {
            $locations = $this->locations->active($workspace);

            $services = $capabilities['services'] ? $this->bookings->services($workspace) : [];
            $professionals = $capabilities['professionals'] ? $this->bookings->professionals($workspace) : [];

            // Ava's own agenda: say where each service is offered and where each professional works, so the filters and the dialog only combine what really
            // exists together; a member limited to selected locations only gets what is offered in theirs (or everywhere).
            if (! $capabilities['external'] && $this->locations->enabled($workspace)) {
                [$services, $professionals] = $this->relatedToLocations($workspace, $request, $services, $professionals);
            }

            return [
                'services' => $services,
                'professionals' => $professionals,
                // Locations of Ava's own agenda only; `locationRequired` = the Workspace has several, so every booking must name one.
                'locations' => $capabilities['external'] ? [] : $locations->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])->values()->all(),
                'locationRequired' => ! $capabilities['external'] && $locations->count() > 1,
                'currency' => $workspace->settingsOrDefault()->currency,
                'error' => null,
            ];
        } catch (BookingException $exception) {
            return ['services' => [], 'professionals' => [], 'locations' => [], 'locationRequired' => false, 'currency' => $workspace->settingsOrDefault()->currency, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Adds to Ava's own services and professionals the locations they belong to: a service is offered where `location_service` says (no rows = everywhere), a
     * professional works where their weekly hours say (an hour with no location = everywhere), and the services a professional gives. A member limited to selected
     * locations only keeps what is offered in one of theirs or everywhere.
     *
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>  $professionals
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function relatedToLocations(Workspace $workspace, ?Request $request, array $services, array $professionals): array
    {
        $serviceLocations = DB::table('location_service')->where('workspace_id', $workspace->id)->get(['service_id', 'location_id'])->groupBy('service_id')->map(fn ($rows) => $rows->pluck('location_id')->map(fn ($id) => (int) $id)->all());
        $hours = ScheduleRule::where('workspace_id', $workspace->id)->get(['professional_id', 'location_id'])->groupBy('professional_id');
        $gives = DB::table('professional_service')->where('workspace_id', $workspace->id)->get(['professional_id', 'service_id'])->groupBy('professional_id')->map(fn ($rows) => $rows->pluck('service_id')->map(fn ($id) => (int) $id)->all());
        $allowed = app(LocationScope::class)->allowedIds($workspace, $request?->user());

        $services = array_map(fn (array $service) => $service + ['locationIds' => $serviceLocations[$service['id']] ?? [], 'everywhere' => ! isset($serviceLocations[$service['id']])], $services);
        $professionals = array_map(fn (array $professional) => $professional + [
            'locationIds' => collect($hours[$professional['id']] ?? [])->pluck('location_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'everywhere' => collect($hours[$professional['id']] ?? [])->contains(fn ($rule) => $rule->location_id === null),
            'serviceIds' => $gives[$professional['id']] ?? [],
        ], $professionals);

        if ($allowed !== null) {
            $services = array_values(array_filter($services, fn (array $service) => $service['everywhere'] || array_intersect($service['locationIds'], $allowed) !== []));
            $professionals = array_values(array_filter($professionals, fn (array $professional) => $professional['everywhere'] || array_intersect($professional['locationIds'], $allowed) !== []));
        }

        return [$services, $professionals];
    }

    /** Only the minimum about who books: name, phone, email. Searched by those. @return array<string, mixed> */
    private function customers(Request $request, Workspace $workspace): array
    {
        $search = trim((string) $request->query('search', ''));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        $page = ListPagination::paginate($workspace->bookingCustomers()->withCount('appointments')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereRaw("lower(name) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(email, '')) like ? escape '!'", [$like])->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $search).'%')))
            ->orderBy('name'), $request, 'customers');

        return [
            'data' => $page->getCollection()->map(fn (BookingCustomer $customer) => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email, 'appointments' => $customer->appointments_count])->values()->all(),
            'meta' => ListPagination::meta($page),
        ];
    }

    /** @return array<string, mixed> */
    private function settings(Workspace $workspace, BookingSetting $setting): array
    {
        return [
            'values' => $setting->only(['provider', 'requires_confirmation', 'min_notice_minutes', 'max_advance_days', 'cancel_notice_hours', 'slot_step_minutes', 'require_phone', 'require_email', 'public_enabled']),
            // An external agenda is offered ONLY where it can be used: the plan includes Proveedores de agenda AND the integration is configured and active.
            // The one the Workspace already selected stays listed (as unavailable) so a broken setup is visible and can be fixed; every other Workspace never sees it.
            'providers' => collect(config('bookings.providers'))
                ->filter(fn (array $provider, string $key) => $key === 'internal' || $setting->provider === $key || $this->providerUsable($workspace, $key))
                ->map(fn (array $provider, string $key) => ['value' => $key, 'label' => $provider['label'], 'available' => $key === 'internal' || $this->providerUsable($workspace, $key)])->values()->all(),
            // Whether the plan includes external agendas at all: only then does the page say that one can be connected in Integraciones.
            'externalInPlan' => $this->modules->allows($workspace, 'calendar_providers'),
            'steps' => config('bookings.steps'),
            'publicUrl' => $setting->public_enabled && $setting->public_key ? route('booking.public', $setting->public_key) : null,
            'notifications' => $this->notificationSettings($workspace, $setting),
        ];
    }

    /** What the screen of confirmations and reminders needs: the values, what the Workspace can really send and why not. @return array<string, mixed> */
    private function notificationSettings(Workspace $workspace, BookingSetting $setting): array
    {
        $ready = $this->notifier->ready();
        $channel = $ready ? $this->notifier->channel($workspace) : ['ok' => false, 'code' => 'migration', 'integration' => null];
        $problems = [
            'migration' => 'Falta aplicar la última migración de la base de datos en este entorno.',
            'plan' => 'El plan de este Workspace debe incluir Servicios y Reservas e Integraciones para enviar avisos.',
            'no_whatsapp' => 'No hay una cuenta de WhatsApp activa: conéctala en Integraciones.',
            'several_whatsapp' => 'Hay varias cuentas de WhatsApp activas: elige cuál usar para los avisos.',
            'chosen_account_off' => 'La cuenta de WhatsApp elegida está apagada o ya no existe: elige otra.',
        ];

        return [
            'ready' => $ready,
            'values' => $ready ? [
                'notify_received' => (bool) $setting->notify_received, 'notify_confirmed' => (bool) $setting->notify_confirmed, 'reminders_enabled' => (bool) $setting->reminders_enabled,
                'reminder_offsets' => $setting->offsets(), 'notify_integration_id' => $setting->notify_integration_id, 'messages' => (object) ($setting->notify_messages ?? []),
            ] : ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => false, 'reminder_offsets' => [], 'notify_integration_id' => null, 'messages' => (object) []],
            'canSend' => $channel['ok'] && $setting->provider === 'internal',
            'problem' => $setting->provider !== 'internal' ? 'Los avisos solo funcionan con la agenda de Ava.' : ($problems[$channel['code']] ?? null),
            'accounts' => $workspace->integrations()->where('type', 'whatsapp')->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($account) => ['id' => $account->id, 'name' => $account->name])->all(),
            'variables' => config('bookings.notifications.variables'),
            'defaults' => config('bookings.notifications.default_texts'),
            'limits' => config('bookings.notifications.offsets') + ['text_max' => config('bookings.notifications.text_max'), 'window_hours' => config('bookings.notifications.window_hours')],
        ];
    }

    private function providerUsable(Workspace $workspace, string $provider): bool
    {
        $type = config("bookings.providers.{$provider}.integration");

        return $type && $this->modules->allows($workspace, 'calendar_providers') && $workspace->integrations()->where('type', $type)->where('is_active', true)->exists();
    }
}
