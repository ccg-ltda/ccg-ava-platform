<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\Locations;
use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveServiceRequest;
use App\Models\Service;
use App\Services\LocationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Servicios: what the Workspace offers to be booked. With Ava's own agenda they are created and edited here (never deleted, only
 * deactivated); with an external agenda the page shows what that agenda reports, read only, or says why it cannot.
 */
class ServiceController extends Controller
{
    use HandlesBooking;

    public function __construct(private readonly BookingManager $bookings, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $capabilities = $this->bookings->capabilities($workspace);
        $external = null;
        $error = null;

        if ($capabilities['external']) {
            try {
                $external = $capabilities['services'] ? $this->bookings->services($workspace) : [];
            } catch (BookingException $exception) {
                $error = $exception->getMessage();
            }
        }

        $scope = app(LocationScope::class);
        // Before the locations migration there is no location_service table: services then have no location to show or change.
        $pivot = app(Locations::class)->schemaReady();
        $locationsOf = $capabilities['external'] || ! $pivot ? collect() : DB::table('location_service')->where('workspace_id', $workspace->id)->get(['service_id', 'location_id'])->groupBy('service_id')->map(fn ($rows) => $rows->pluck('location_id')->map(fn ($id) => (int) $id)->all());

        return Inertia::render('Services/Index', [
            'capabilities' => $capabilities,
            'services' => $capabilities['external'] ? [] : $workspace->services()->when($pivot && $scope->restricted($workspace, $request->user()), fn ($query) => $query->where(fn ($w) => $w->whereNotIn('id', DB::table('location_service')->where('workspace_id', $workspace->id)->select('service_id'))->orWhereIn('id', DB::table('location_service')->whereIn('location_id', $scope->allowedIds($workspace, $request->user()))->select('service_id'))))->with('resources:id')->withCount(['professionals', 'appointments as upcoming_count' => fn ($query) => $query->upcoming()])->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'category' => $service->category,
                'durationMinutes' => $service->duration_minutes,
                'bufferMinutes' => $service->buffer_minutes,
                'price' => $service->price === null ? null : (float) $service->price,
                'isActive' => $service->is_active,
                'professionalsCount' => $service->professionals_count,
                'upcomingCount' => $service->upcoming_count,
                'resourceIds' => $service->resources->pluck('id')->all(),
                // Where it is offered (empty = in every location, as every service created before this was) and whether THIS member may change it.
                'locationIds' => $locationsOf[$service->id] ?? [],
                'offeredEverywhere' => ! isset($locationsOf[$service->id]),
                'canManage' => $scope->mayChange($workspace, $request->user(), $locationsOf[$service->id] ?? []),
            ])->all(),
            'external' => $external,
            'externalError' => $error,
            'locations' => $capabilities['external'] || ! $pivot ? [] : app(Locations::class)->active($workspace)->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])->values()->all(),
            'resources' => $capabilities['external'] ? [] : $workspace->bookableResources()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind'])->all(),
            'currency' => $workspace->settingsOrDefault()->currency,
        ]);
    }

    public function store(SaveServiceRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        return $this->attempt(function () use ($request, $workspace) {
            $this->assertOwnAgenda($workspace);
            $service = $workspace->services()->create($request->service());
            $this->syncResources($service, $request->resourceIds());
            $this->syncLocations($service, $request->locationIds());
        }, 'Servicio creado correctamente');
    }

    public function update(SaveServiceRequest $request, int $service): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->services()->findOrFail($service);
        $this->assertChanges($request, $model);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $model->update($request->service());
            $this->syncResources($model, $request->resourceIds());
            $this->syncLocations($model, $request->locationIds());
        }, 'Servicio actualizado correctamente');
    }

    public function activate(Request $request, int $service): RedirectResponse
    {
        return $this->toggle($request, $service, true);
    }

    public function deactivate(Request $request, int $service): RedirectResponse
    {
        return $this->toggle($request, $service, false);
    }

    private function toggle(Request $request, int $service, bool $active): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->services()->findOrFail($service);
        $this->assertChanges($request, $model);

        return $this->attempt(function () use ($workspace, $model, $active) {
            $this->assertOwnAgenda($workspace);
            $model->update(['is_active' => $active]);

            return ! $active && ($pending = $model->appointments()->upcoming()->count()) > 0
                ? "«{$model->name}» ya no se ofrece, pero tiene {$pending} cita(s) próximas. Siguen vigentes: revísalas en la agenda."
                : null;
        }, $active ? 'Servicio activado correctamente' : 'Servicio desactivado correctamente');
    }

    /** A limited member changes only a service that is offered somewhere and ONLY in their locations (a service offered everywhere belongs to every location). */
    private function assertChanges(Request $request, Service $service): void
    {
        if (! app(Locations::class)->schemaReady()) {
            return;
        }

        $offeredIn = DB::table('location_service')->where('service_id', $service->id)->pluck('location_id')->map(fn ($id) => (int) $id)->all();

        abort_unless(app(LocationScope::class)->mayChange($request->attributes->get('workspace'), $request->user(), $offeredIn), 403, 'Este servicio se ofrece en sedes a las que no tienes acceso.');
    }

    /**
     * The locations that offer the service, written to the SAME pivot the Sedes page edits (`location_service`; no second assignment exists). The pivot rows
     * carry the Workspace so the database refuses a location of another one. Locations the member may not touch keep their rows (a limited member only
     * adds or removes their own); an empty list for an unrestricted member means "offered in every location", as before.
     *
     * @param  list<int>  $ids
     */
    private function syncLocations(Service $service, array $ids): void
    {
        if (! app(Locations::class)->schemaReady()) {
            return;
        }

        $scope = app(LocationScope::class);
        $user = auth()->user();
        $names = fn () => $service->locations()->orderBy('locations.name')->pluck('locations.name')->implode(', ');
        $before = $names();
        $allowed = $scope->allowedIds($service->workspace, $user);
        $keep = $allowed === null ? [] : $service->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->reject(fn ($id) => in_array($id, $allowed, true))->all();

        $service->locations()->sync(collect([...$ids, ...$keep])->unique()->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $service->workspace_id]])->all());
        $after = $names();

        if ($before !== $after) {
            $this->audit->record('updated', 'service', $service->id, $service->name, [AuditLogger::change('Sedes donde se ofrece', $before ?: null, $after ?: null)], $service->workspace);
        }
    }

    /** The resources of a service are of the same Workspace (validated), and the pivot rows carry it so the database enforces it too. */
    private function syncResources(Service $service, array $ids): void
    {
        $names = fn () => $service->resources()->orderBy('bookable_resources.name')->pluck('bookable_resources.name')->implode(', ');
        $before = $names();
        $service->resources()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $service->workspace_id]])->all());
        $after = $names();

        if ($before !== $after) {
            $this->audit->record('updated', 'service', $service->id, $service->name, [AuditLogger::change('Recursos que necesita', $before ?: null, $after ?: null)], $service->workspace);
        }
    }
}
