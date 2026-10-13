<?php

namespace Tests\Support;

use App\Booking\AppointmentService;
use App\Booking\AvailabilityCalculator;
use App\Booking\CalendarProvider;
use App\Booking\CalendarProviders;
use App\Integrations\IntegrationRegistry;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use App\Services\ModuleAccess;
use Carbon\CarbonImmutable;

/**
 * Fixtures of the booking tests: a Workspace whose plan includes Servicios y Reservas, a service, a professional who works
 * Monday to Friday 09:00-17:00 (UTC) and a fixed "now" (Monday 2026-10-12 08:00 UTC) so every availability result is predictable.
 */
trait BuildsBookings
{
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC');
    }

    protected function freezeAgendaTime(): void
    {
        $this->travelTo($this->now());
    }

    /** @param  list<string>  $modules */
    protected function bookingWorkspace(string $code = 'SALON', array $modules = ['dashboard', 'service_catalog', 'professionals', 'bookings']): Workspace
    {
        $organization = Organization::first() ?? Organization::create(['name' => 'Org']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'code' => $code, 'name' => "Salón {$code}"]);
        $workspace->settings()->create(array_merge(WorkspaceSetting::defaults(), ['timezone' => 'UTC']));
        $this->contractModules($workspace, $modules);

        return $workspace;
    }

    /** @param  list<string>  $modules */
    protected function contractModules(Workspace $workspace, array $modules): void
    {
        $plan = Plan::create(['name' => 'Plan '.$workspace->code.Plan::count()]);
        $plan->modules()->sync(Module::whereIn('key', $modules)->pluck('id'));
        $workspace->update(['plan_id' => $plan->id]);
    }

    protected function member(Workspace $workspace, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    protected function actAsMember(Workspace $workspace, string $role = 'admin'): User
    {
        $this->seedRoleCatalog();
        $user = $this->member($workspace, $role);
        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return $user;
    }

    protected function makeService(Workspace $workspace, array $attributes = []): Service
    {
        return $workspace->services()->create($attributes + ['name' => 'Manicure', 'duration_minutes' => 60, 'buffer_minutes' => 0, 'price' => 25])->fresh();
    }

    /** @param  list<int>  $weekdays */
    protected function makeProfessional(Workspace $workspace, array $services, string $name = 'Ana', array $weekdays = [1, 2, 3, 4, 5], string $from = '09:00:00', string $to = '17:00:00'): Professional
    {
        $professional = $workspace->professionals()->create(['name' => $name])->fresh();
        $professional->services()->sync(collect($services)->mapWithKeys(fn (Service $service) => [$service->id => ['workspace_id' => $workspace->id]])->all());

        foreach ($weekdays as $weekday) {
            $professional->scheduleRules()->create(['workspace_id' => $workspace->id, 'weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to]);
        }

        return $professional;
    }

    protected function calculator(): AvailabilityCalculator
    {
        return app(AvailabilityCalculator::class);
    }

    protected function appointments(): AppointmentService
    {
        return app(AppointmentService::class);
    }

    /** Replaces the agenda of EVERY Workspace with a simulated external one (see SimulatedCalendar: it is not Booker). */
    protected function useSimulatedAgenda(SimulatedCalendar $calendar): void
    {
        $this->app->singleton(CalendarProviders::class, fn ($app) => new class($calendar, $app->make(IntegrationRegistry::class), $app->make(ModuleAccess::class), $app->make(AvailabilityCalculator::class), $app->make(AppointmentService::class)) extends CalendarProviders
        {
            public function __construct(private readonly CalendarProvider $simulated, IntegrationRegistry $types, ModuleAccess $modules, AvailabilityCalculator $calculator, AppointmentService $appointments)
            {
                parent::__construct($types, $modules, $calculator, $appointments);
            }

            public function for(Workspace $workspace, ?string $provider = null): CalendarProvider
            {
                return $this->simulated;
            }
        });
    }

    protected function useBookerSetting(Workspace $workspace): void
    {
        $workspace->bookingSetting()->updateOrCreate([], ['provider' => 'booker']);
    }
}
