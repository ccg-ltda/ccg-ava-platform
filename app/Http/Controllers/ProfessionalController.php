<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Http\Controllers\Concerns\HandlesBooking;
use App\Http\Requests\SaveProfessionalRequest;
use App\Models\Professional;
use App\Models\ScheduleException;
use App\Models\ScheduleRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profesionales y disponibilidad: who performs the services, their weekly schedule, the optional resources and the days
 * nobody can be booked. With Ava's own agenda everything here is edited (nothing is deleted except a schedule exception, which is
 * a setting); with an external agenda the professionals are that agenda's and are shown read only.
 */
class ProfessionalController extends Controller
{
    use HandlesBooking;

    private const DAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public function __construct(private readonly BookingManager $bookings, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $capabilities = $this->bookings->capabilities($workspace);
        $external = null;
        $error = null;

        if ($capabilities['external']) {
            try {
                $external = $capabilities['professionals'] ? $this->bookings->professionals($workspace) : [];
            } catch (BookingException $exception) {
                $error = $exception->getMessage();
            }
        }

        $own = ! $capabilities['external'];

        return Inertia::render('Professionals/Index', [
            'capabilities' => $capabilities,
            'days' => collect(self::DAYS)->map(fn ($label, $weekday) => ['value' => $weekday, 'label' => $label])->values()->all(),
            'professionals' => $own ? $workspace->professionals()->with(['services:id', 'scheduleRules'])->withCount(['appointments as upcoming_count' => fn ($query) => $query->upcoming()])->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Professional $professional) => [
                'id' => $professional->id,
                'name' => $professional->name,
                'specialty' => $professional->specialty,
                'phone' => $professional->phone,
                'isActive' => $professional->is_active,
                'upcomingCount' => $professional->upcoming_count,
                'serviceIds' => $professional->services->pluck('id')->all(),
                'schedule' => $professional->scheduleRules->sortBy(['weekday', 'starts_at'])->map(fn (ScheduleRule $rule) => ['weekday' => $rule->weekday, 'startsAt' => substr($rule->starts_at, 0, 5), 'endsAt' => substr($rule->ends_at, 0, 5)])->values()->all(),
            ])->all() : [],
            'external' => $external,
            'externalError' => $error,
            'services' => $own ? $workspace->services()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all() : [],
            'resources' => $own ? $workspace->bookableResources()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'kind', 'is_active'])->map(fn ($resource) => ['id' => $resource->id, 'name' => $resource->name, 'kind' => $resource->kind, 'isActive' => $resource->is_active])->all() : [],
            'exceptions' => $own ? $workspace->scheduleExceptions()->with('professional:id,name')->where('date_to', '>=', now($workspace->settingsOrDefault()->timezone)->subDays(30)->toDateString())->orderBy('date_from')->get()->map(fn (ScheduleException $exception) => [
                'id' => $exception->id,
                'professionalId' => $exception->professional_id,
                'professional' => $exception->professional?->name,
                'dateFrom' => $exception->date_from->toDateString(),
                'dateTo' => $exception->date_to->toDateString(),
                'startsAt' => $exception->starts_at ? substr($exception->starts_at, 0, 5) : null,
                'endsAt' => $exception->ends_at ? substr($exception->ends_at, 0, 5) : null,
                'reason' => $exception->reason,
            ])->all() : [],
        ]);
    }

    public function store(SaveProfessionalRequest $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');

        return $this->attempt(function () use ($request, $workspace) {
            $this->assertOwnAgenda($workspace);
            $professional = $workspace->professionals()->create($request->professional());
            $this->apply($professional, $request);
        }, 'Profesional creado correctamente');
    }

    public function update(SaveProfessionalRequest $request, int $professional): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->professionals()->findOrFail($professional);

        return $this->attempt(function () use ($request, $workspace, $model) {
            $this->assertOwnAgenda($workspace);
            $model->update($request->professional());
            $this->apply($model, $request);
        }, 'Profesional actualizado correctamente');
    }

    public function activate(Request $request, int $professional): RedirectResponse
    {
        return $this->toggle($request, $professional, true);
    }

    public function deactivate(Request $request, int $professional): RedirectResponse
    {
        return $this->toggle($request, $professional, false);
    }

    private function toggle(Request $request, int $professional, bool $active): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->professionals()->findOrFail($professional);

        return $this->attempt(function () use ($workspace, $model, $active) {
            $this->assertOwnAgenda($workspace);
            $model->update(['is_active' => $active]);

            return ! $active && ($pending = $model->appointments()->upcoming()->count()) > 0
                ? "{$model->name} ya no se ofrece, pero tiene {$pending} cita(s) próximas. Siguen vigentes: reprográmalas o cancélalas en la agenda."
                : null;
        }, $active ? 'Profesional activado correctamente' : 'Profesional desactivado correctamente');
    }

    /** Services and weekly schedule of the professional; a change of the schedule is one audit event with the before and after. */
    private function apply(Professional $professional, SaveProfessionalRequest $request): void
    {
        $names = fn () => $professional->services()->orderBy('services.name')->pluck('services.name')->implode(', ');
        $servicesBefore = $names();
        $professional->services()->sync(collect($request->serviceIds())->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $professional->workspace_id]])->all());

        if ($servicesBefore !== ($servicesAfter = $names())) {
            $this->audit->record('updated', 'professional', $professional->id, $professional->name, [AuditLogger::change('Servicios que presta', $servicesBefore ?: null, $servicesAfter ?: null)], $professional->workspace);
        }

        if (! $request->hasSchedule()) {
            return;
        }

        $before = $this->summary($professional->scheduleRules()->get()->map(fn ($rule) => ['weekday' => $rule->weekday, 'starts_at' => $rule->starts_at, 'ends_at' => $rule->ends_at])->all());
        $professional->scheduleRules()->delete();
        collect($request->schedule())->each(fn (array $row) => $professional->scheduleRules()->create($row + ['workspace_id' => $professional->workspace_id]));
        $after = $this->summary($request->schedule());

        if ($before !== $after) {
            $this->audit->record('updated', 'professional', $professional->id, $professional->name, [AuditLogger::change('Horario semanal', $before ?: null, $after ?: null)], $professional->workspace);
        }
    }

    /** "Lunes 09:00-13:00, 14:00-18:00; Martes ..." */
    private function summary(array $rows): string
    {
        return collect($rows)->sortBy(['weekday', 'starts_at'])->groupBy('weekday')
            ->map(fn ($day, $weekday) => self::DAYS[$weekday].' '.$day->map(fn ($row) => substr($row['starts_at'], 0, 5).'-'.substr($row['ends_at'], 0, 5))->implode(', '))
            ->implode('; ');
    }
}
