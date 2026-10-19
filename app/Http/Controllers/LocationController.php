<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\Locations;
use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveLocationRequest;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        // The code can be deployed before the migration: the page opens and says what is missing instead of failing on a missing table.
        $ready = $this->locations->schemaReady();

        return Inertia::render('Locations/Index', [
            'capabilities' => $capabilities,
            'schemaPending' => ! $ready,
            'locations' => ! $ready ? [] : $workspace->locations()->with('services:id')->withCount(['appointments as upcoming_count' => fn ($query) => $query->upcoming()])->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'phone' => $location->phone,
                'notes' => $location->notes,
                'isActive' => $location->is_active,
                'upcomingCount' => $location->upcoming_count,
                'serviceIds' => $location->services->pluck('id')->all(),
            ])->all(),
            'services' => $workspace->services()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }

    public function store(SaveLocationRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        return $this->attempt(function () use ($request, $workspace) {
            $this->assertOwnAgenda($workspace);
            $this->assertSchema();
            $location = $workspace->locations()->create($request->location());
            $this->syncServices($location, $request->serviceIds());
        }, 'Sede creada correctamente');
    }

    public function update(SaveLocationRequest $request, int $location): RedirectResponse
    {
        if (! $this->locations->schemaReady()) {
            return $this->attempt(fn () => $this->assertSchema(), '');
        }

        $workspace = $request->attributes->get('workspace');
        $model = $workspace->locations()->findOrFail($location);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $this->assertSchema();
            $model->update($request->location());
            $this->syncServices($model, $request->serviceIds());
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

        return $this->attempt(function () use ($workspace, $model, $active) {
            $this->assertOwnAgenda($workspace);
            $model->update(['is_active' => $active]);

            return ! $active && ($pending = $model->appointments()->upcoming()->count()) > 0
                ? "«{$model->name}» ya no se ofrece, pero tiene {$pending} cita(s) próximas. Siguen vigentes, pero no se pueden reprogramar: cancélalas y créalas en otra sede."
                : null;
        }, $active ? 'Sede activada correctamente' : 'Sede desactivada correctamente');
    }

    private function assertSchema(): void
    {
        if (! $this->locations->schemaReady()) {
            throw BookingException::state('Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
        }
    }

    /** The services a location offers (none = all of them). They are of the same Workspace (validated) and the pivot rows carry it so the database enforces it too. */
    private function syncServices(Location $location, array $ids): void
    {
        $names = fn () => $location->services()->orderBy('services.name')->pluck('services.name')->implode(', ');
        $before = $names();
        $location->services()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $location->workspace_id]])->all());
        $after = $names();

        if ($before !== $after) {
            $this->audit->record('updated', 'location', $location->id, $location->name, [AuditLogger::change('Servicios que ofrece', $before ?: null, $after ?: null)], $location->workspace);
        }
    }
}
