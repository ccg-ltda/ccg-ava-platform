<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Where a service can be booked. The ONE rule (Locations::offers, used by the calculator, the booking, the public page and the options of the agenda form):
 *   1. the sede must be an ACTIVE sede of the Workspace and the service an ACTIVE service of it;
 *   2. a sede marked «Todos los servicios» offers every service (the mark wins over a service's own list);
 *   3. otherwise a service with no sedes of its own is offered in every sede, and one with sedes only in those.
 * The form narrows the choices with the same data, and the server refuses whatever the browser sends against the rule.
 */
class AgendaServiceLocationRuleTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations'];

    private Workspace $workspace;

    /** @var array<string, Location> */
    private array $sedes = [];

    private Service $beard;

    private Service $color;

    private Professional $david;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);

        foreach (['Norte', 'Sur', 'Centro', 'Oriente', 'Occidente', 'Parque'] as $name) {
            $this->sedes[$name] = $this->workspace->locations()->create(['name' => $name]);
        }

        $this->beard = $this->makeService($this->workspace, ['name' => 'Arreglo de barba', 'duration_minutes' => 30]);
        $this->color = $this->makeService($this->workspace, ['name' => 'Coloración', 'duration_minutes' => 30]);
        $this->offer($this->beard, ['Norte', 'Sur']);
        $this->offer($this->color, ['Centro', 'Oriente']);
        // Works every day of the week in EVERY sede (hours with no sede count everywhere), so the only thing in play is the rule of coverage.
        $this->david = $this->makeProfessional($this->workspace, [$this->beard, $this->color], 'David Quintero');
        $this->actAsMember($this->workspace);
    }

    /** @param  list<string>  $names */
    private function offer(Service $service, array $names): void
    {
        $service->locations()->sync(collect($names)->mapWithKeys(fn (string $name) => [$this->sedes[$name]->id => ['workspace_id' => $this->workspace->id]])->all());
    }

    private function book(Service $service, string $sede, array $extra = [])
    {
        $location = $this->sedes[$sede] ?? null;

        return $this->post('/bookings', $extra + ['service_id' => $service->id, 'location_id' => $location?->id ?? $sede, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']]);
    }

    public function test_the_form_gets_the_sedes_of_each_service_so_it_can_offer_only_those(): void
    {
        $this->get('/bookings')->assertInertia(function ($page) {
            $page->has('options.locations', 6);
            $services = collect($page->toArray()['props']['options']['services'])->keyBy('name');

            $this->assertEqualsCanonicalizing([$this->sedes['Norte']->id, $this->sedes['Sur']->id], $services['Arreglo de barba']['locationIds']);
            $this->assertEqualsCanonicalizing([$this->sedes['Centro']->id, $this->sedes['Oriente']->id], $services['Coloración']['locationIds']);
            $this->assertFalse($services['Arreglo de barba']['everywhere']);
        });
    }

    public function test_a_service_is_booked_in_a_sede_that_offers_it_and_refused_in_the_other_four(): void
    {
        $this->book($this->beard, 'Norte')->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, Appointment::count());

        foreach (['Centro', 'Oriente', 'Occidente', 'Parque'] as $sede) {
            $this->book($this->beard, $sede, ['starts_at' => '2026-10-14T10:00:00Z'])->assertSessionHas('error');
        }

        $this->assertSame(1, Appointment::count(), 'a forged sede books nothing');
        $this->assertSame($this->sedes['Norte']->id, Appointment::firstOrFail()->location_id);
    }

    public function test_changing_to_another_service_changes_where_it_can_be_booked(): void
    {
        $this->book($this->color, 'Norte')->assertSessionHas('error');
        $this->book($this->color, 'Centro')->assertSessionHas('success');
        $this->book($this->color, 'Oriente', ['starts_at' => '2026-10-13T12:00:00Z'])->assertSessionHas('success');
    }

    public function test_a_service_with_no_sedes_of_its_own_is_offered_in_all_of_them(): void
    {
        $open = $this->makeService($this->workspace, ['name' => 'Consulta']);
        $this->david->services()->attach($open->id, ['workspace_id' => $this->workspace->id]);

        foreach (['Norte', 'Parque'] as $index => $sede) {
            $this->book($open, $sede, ['starts_at' => '2026-10-1'.(3 + $index).'T10:00:00Z'])->assertSessionHas('success');
        }
    }

    public function test_a_sede_marked_all_services_offers_even_a_service_restricted_elsewhere_and_the_marking_wins(): void
    {
        $this->book($this->beard, 'Parque')->assertSessionHas('error');

        $this->sedes['Parque']->update(['offers_all_services' => true]);

        $this->book($this->beard, 'Parque')->assertSessionHas('success');
        $this->get('/bookings')->assertInertia(function ($page) {
            $services = collect($page->toArray()['props']['options']['services'])->keyBy('name');
            $this->assertContains($this->sedes['Parque']->id, $services['Arreglo de barba']['locationIds'], 'the form and the server use the same rule');
        });
    }

    public function test_inactive_foreign_and_malformed_sedes_and_inactive_services_are_refused(): void
    {
        $this->sedes['Norte']->update(['is_active' => false]);
        $this->book($this->beard, 'Norte')->assertSessionHas('error');

        $foreign = $this->bookingWorkspace('OTRO', self::MODULES)->locations()->create(['name' => 'Ajena']);
        $this->book($this->beard, 'Sur', ['location_id' => $foreign->id])->assertSessionHas('error');
        $this->book($this->beard, 'Sur', ['location_id' => 'abc'])->assertSessionHasErrors('location_id');

        $this->beard->update(['is_active' => false]);
        $this->book($this->beard, 'Sur')->assertSessionHas('error');

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_service_whose_only_sede_is_inactive_has_no_sede_to_offer_and_cannot_be_booked(): void
    {
        $this->offer($this->beard, ['Norte']);
        $this->sedes['Norte']->update(['is_active' => false]);

        $this->get('/bookings')->assertInertia(fn ($page) => $page->has('options.locations', 5)); // the form only lists active sedes
        $this->book($this->beard, 'Sur')->assertSessionHas('error');
        $this->assertSame(0, Appointment::count());
    }

    public function test_the_professional_must_work_in_the_sede_and_the_server_rechecks_the_time_and_the_rest(): void
    {
        $this->david->scheduleRules()->delete();
        $this->david->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'location_id' => $this->sedes['Sur']->id]);

        $this->book($this->beard, 'Norte', ['professional_id' => $this->david->id])->assertSessionHas('error');        // works only in Sur
        $this->book($this->beard, 'Sur', ['professional_id' => $this->david->id, 'starts_at' => '2026-10-13T14:00:00Z'])->assertSessionHas('error'); // outside his hours
        $this->book($this->beard, 'Sur', ['professional_id' => $this->david->id, 'starts_at' => '2026-10-13T10:00:00Z'])->assertSessionHas('success');

        // The same professional is busy at 10:00: a second booking in the same sede at the same time is a conflict.
        $this->book($this->beard, 'Sur', ['professional_id' => $this->david->id, 'starts_at' => '2026-10-13T10:00:00Z'])->assertSessionHas('error');
        $this->assertSame(1, Appointment::count());
    }
}
