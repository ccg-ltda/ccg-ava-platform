<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveServiceRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Services/Index', [
            'capabilities' => $capabilities,
            'services' => $capabilities['external'] ? [] : $workspace->services()->with('resources:id')->withCount(['professionals', 'appointments as upcoming_count' => fn ($query) => $query->upcoming()])->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Service $service) => [
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
            ])->all(),
            'external' => $external,
            'externalError' => $error,
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
        }, 'Servicio creado correctamente');
    }

    public function update(SaveServiceRequest $request, int $service): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->services()->findOrFail($service);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $model->update($request->service());
            $this->syncResources($model, $request->resourceIds());
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

        return $this->attempt(function () use ($workspace, $model, $active) {
            $this->assertOwnAgenda($workspace);
            $model->update(['is_active' => $active]);

            return ! $active && ($pending = $model->appointments()->upcoming()->count()) > 0
                ? "«{$model->name}» ya no se ofrece, pero tiene {$pending} cita(s) próximas. Siguen vigentes: revísalas en la agenda."
                : null;
        }, $active ? 'Servicio activado correctamente' : 'Servicio desactivado correctamente');
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
