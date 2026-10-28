<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\Locations;
use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveLocationRequest;
use App\Models\Location;
use App\Models\Workspace;
use App\Services\LocationScope;
use App\Services\WorkspaceLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sedes: the places where the business attends. They belong to Ava's own agenda (an external agenda has its own locations, so with one
 * chosen the page is read only and says so). Never deleted, only deactivated, so the appointments made there keep their history.
 * Reading is `view-locations`; creating, editing and activating are `create-locations`, `edit-locations` and `activate-locations`.
 */
class LocationController extends Controller
{
    use HandlesBooking;

    public function __construct(private readonly BookingManager $bookings, private readonly AuditLogger $audit, private readonly Locations $locations) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $capabilities = $this->bookings->capabilities($workspace);
        $allowed = app(LocationScope::class)->allowedIds($workspace, $request->user()); // null = every location

        // The code can be deployed before the migration: the page opens and says what is missing instead of failing on a missing table.
        $ready = $this->locations->schemaReady();

        $visible = $ready ? $workspace->locations()->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $hours = $this->locations->weeklyHours($workspace, $visible);

        return Inertia::render('Locations/Index', [
            'capabilities' => $capabilities,
            'schemaPending' => ! $ready,
            'locations' => ! $ready ? [] : $workspace->locations()->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))->with('services:id')->withCount(['appointments as upcoming_count' => fn ($query) => $query->upcoming()])->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'phone' => $location->phone,
                'notes' => $location->notes,
                'isActive' => $location->is_active,
                'upcomingCount' => $location->upcoming_count,
                'serviceIds' => $location->services->pluck('id')->all(),
                'offersAllServices' => (bool) ($location->offers_all_services ?? false),
                'hours' => $hours[$location->id] === null ? null : collect($hours[$location->id])->map(fn (array $day, int $weekday) => ['weekday' => $weekday, 'opensAt' => sprintf('%02d:%02d', intdiv($day[0], 60), $day[0] % 60), 'closesAt' => sprintf('%02d:%02d', intdiv($day[1], 60), $day[1] % 60)])->values()->all(),
            ])->all(),
            'services' => $workspace->services()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active'])->map(fn ($service) => ['id' => $service->id, 'name' => $service->name, 'isActive' => (bool) $service->is_active])->all(),
        ]);
    }

    public function store(SaveLocationRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        // A member limited to selected locations can not create one: it would be outside their scope the moment it exists.
        abort_if(app(LocationScope::class)->restricted($workspace, $request->user()), 403, 'Solo quien tiene acceso a todas las sedes puede crear una sede.');

        return $this->attempt(function () use ($request, $workspace) {
            $this->assertOwnAgenda($workspace);
            $this->assertSchema();
            $this->refusedAsLimit($workspace, fn () => DB::transaction(function () use ($request, $workspace) {
                // The limit is checked and the row written under the Workspace's row lock: two simultaneous creations can not both take the last slot.
                $limits = app(WorkspaceLimits::class);
                $limits->lockWorkspace($workspace);
                $limits->assertCanAddLocation($workspace);
                $location = $workspace->locations()->create($request->location());
                $this->syncServices($location, $request->serviceIds(), $request->offersAllServices());
                $this->syncHours($location, $request);
            }));
        }, 'Sede creada correctamente');
    }

    public function update(SaveLocationRequest $request, int $location): RedirectResponse
    {
        if (! $this->locations->schemaReady()) {
            return $this->attempt(fn () => $this->assertSchema(), '');
        }

        $workspace = $request->attributes->get('workspace');
        $model = $workspace->locations()->findOrFail($location);
        app(LocationScope::class)->assertAllows($workspace, $request->user(), $model->id);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $this->assertSchema();
            $before = (bool) ($model->offers_all_services ?? false);
            $model->update($request->location());
            $this->syncServices($model, $request->serviceIds(), $request->offersAllServices(), $before);
            $this->syncHours($model, $request);
        }, 'Sede actualizada correctamente');
    }

    public function activate(Request $request, int $location): RedirectResponse
    {
        return $this->toggle($request, $location, true);
    }

    public function deactivate(Request $request, int $location): RedirectResponse
    {
        return $this->toggle($request, $location, false);
    }

    private function toggle(Request $request, int $location, bool $active): RedirectResponse
    {
        if (! $this->locations->schemaReady()) {
            return $this->attempt(fn () => $this->assertSchema(), '');
        }

        $workspace = $request->attributes->get('workspace');
        $model = $workspace->locations()->findOrFail($location);
        app(LocationScope::class)->assertAllows($workspace, $request->user(), $model->id);

        return $this->attempt(function () use ($workspace, $model, $active) {
            $this->assertOwnAgenda($workspace);
            $this->refusedAsLimit($workspace, fn () => DB::transaction(function () use ($workspace, $model, $active) {
                if ($active && ! $model->is_active) {
                    $limits = app(WorkspaceLimits::class);
                    $limits->lockWorkspace($workspace);
                    $limits->assertCanAddLocation($workspace);
                }

                $model->update(['is_active' => $active]);
            }));

            return ! $active && ($pending = $model->appointments()->upcoming()->count()) > 0
                ? "«{$model->name}» ya no se ofrece, pero tiene {$pending} cita(s) próximas. Siguen vigentes, pero no se pueden reprogramar: cancélalas y créalas en otra sede."
                : null;
        }, $active ? 'Sede activada correctamente' : 'Sede desactivada correctamente');
    }

    /** Runs a write that may hit the location limit; a refusal is audited after the transaction rolled back, then handed on as the validation error it is. */
    private function refusedAsLimit(Workspace $workspace, \Closure $write): void
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            app(WorkspaceLimits::class)->recordLocationRefused($workspace);

            throw $exception;
        }
    }

    private function assertSchema(): void
    {
        if (! $this->locations->schemaReady()) {
            throw BookingException::state('Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
        }
    }

    /**
     * The opening hours of the sede, written as the form sent them (nothing is written when the form said nothing). The rows are replaced as a set inside the caller's transaction
     * of this request; a sede with no rows has no hours configured. Existing appointments are never touched: hours only decide what is offered from now on.
     */
    private function syncHours(Location $location, SaveLocationRequest $request): void
    {
        if (! $request->hasHoursInput() || ! $this->locations->hoursReady()) {
            return;
        }

        $summary = fn (array $rows) => collect($rows)->map(fn ($row) => ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'][$row['weekday']].' '.substr($row['opens_at'], 0, 5).'-'.substr($row['closes_at'], 0, 5))->implode(', ');
        $before = $summary(DB::table('location_hours')->where('location_id', $location->id)->orderBy('weekday')->get()->map(fn ($row) => ['weekday' => (int) $row->weekday, 'opens_at' => $row->opens_at, 'closes_at' => $row->closes_at])->all());
        $rows = $request->hours();

        DB::table('location_hours')->where('location_id', $location->id)->where('workspace_id', $location->workspace_id)->delete();

        foreach ($rows as $row) {
            DB::table('location_hours')->insert($row + ['workspace_id' => $location->workspace_id, 'location_id' => $location->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $after = $summary($rows);

        if ($before !== $after) {
            $this->audit->record('updated', 'location', $location->id, $location->name, [AuditLogger::change('Horario de atención', $before ?: null, $after ?: null)], $location->workspace);
        }
    }

    /** The services a location offers (none = all of them). They are of the same Workspace (validated) and the pivot rows carry it so the database enforces it too. */
    private function syncServices(Location $location, array $ids, bool $all = false, bool $wasAll = false): void
    {
        if ($all !== $wasAll) {
            $this->audit->record('updated', 'location', $location->id, $location->name, [AuditLogger::change('Ofrece todos los servicios', $wasAll ? 'Sí' : 'No', $all ? 'Sí' : 'No')], $location->workspace);
        }

        // «Todos los servicios»: the flag covers every service, new ones included. The rows already there are not touched (they keep their meaning if it is turned off).
        if ($all) {
            return;
        }

        $names = fn () => $location->services()->orderBy('services.name')->pluck('services.name')->implode(', ');
        $before = $names();
        $location->services()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $location->workspace_id]])->all());
        $after = $names();

        if ($before !== $after) {
            $this->audit->record('updated', 'location', $location->id, $location->name, [AuditLogger::change('Servicios que ofrece', $before ?: null, $after ?: null)], $location->workspace);
        }
    }
}
