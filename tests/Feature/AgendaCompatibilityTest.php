<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * What the agenda offers: only professionals who perform the service, work in the chosen sede and have the whole occupation (attention + rest) inside their
 * hours and clear of exceptions and other appointments. "Now" is Monday 2026-10-12 08:00 UTC; professionals work Monday to Friday 09:00-17:00 unless a test says otherwise.
 */
class AgendaCompatibilityTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations'];

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-13', 'UTC');
    }

    /** @return list<array<string, mixed>> */
    private function slots($service, ?int $professionalId = null, ?int $locationId = null): array
    {
        return $this->calculator()->slots($this->workspace, $service, $professionalId, $this->day(), $this->day()->addDay(), locationId: $locationId);
    }

    /** @return list<string> */
    private function names(array $slots): array
    {
        return array_values(array_unique(array_map(fn (array $slot) => $slot['professional_name'], $slots)));
    }

    public function test_a_professional_who_does_not_perform_the_service_is_never_offered(): void
    {
        $beard = $this->makeService($this->workspace, ['name' => 'Barba']);
        $color = $this->makeService($this->workspace, ['name' => 'Coloración']);
        $this->makeProfessional($this->workspace, [$beard], 'David Quintero');
        $this->makeProfessional($this->workspace, [$beard, $color], 'Laura');

        $this->assertSame(['Laura'], $this->names($this->slots($color)));
        $this->assertSame(['David Quintero', 'Laura'], $this->names($this->slots($beard)));
        $this->assertSame([], $this->slots($color, professionalId: $this->workspace->professionals()->where('name', 'David Quintero')->value('id')), 'naming him does not make him eligible');
    }

    public function test_a_professional_who_only_works_in_another_sede_is_not_offered_in_the_chosen_one(): void
    {
        $color = $this->makeService($this->workspace, ['name' => 'Coloración']);
        $north = $this->workspace->locations()->create(['name' => 'Norte']);
        $center = $this->workspace->locations()->create(['name' => 'Centro']);
        $onlyNorth = $this->makeProfessional($this->workspace, [$color], 'Solo Norte', weekdays: []);
        $both = $this->makeProfessional($this->workspace, [$color], 'Centro y Norte', weekdays: []);
        $onlyNorth->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00', 'location_id' => $north->id]);
        $both->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00', 'location_id' => $north->id]);
        $both->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00', 'location_id' => $center->id]);

        $this->assertSame(['Centro y Norte'], $this->names($this->slots($color, locationId: $center->id)));
        $this->assertSame(['Centro y Norte', 'Solo Norte'], $this->names($this->slots($color, locationId: $north->id)));
    }

    public function test_the_hours_of_one_sede_are_not_offered_in_the_other(): void
    {
        $service = $this->makeService($this->workspace, ['duration_minutes' => 60]);
        $north = $this->workspace->locations()->create(['name' => 'Norte']);
        $south = $this->workspace->locations()->create(['name' => 'Sur']);
        $pro = $this->makeProfessional($this->workspace, [$service], weekdays: []);
        $pro->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'location_id' => $north->id]);
        $pro->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '14:00:00', 'ends_at' => '17:00:00', 'location_id' => $south->id]);
        $at = fn (array $slots) => array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $slots);

        $northStarts = $at($this->slots($service, locationId: $north->id));
        $southStarts = $at($this->slots($service, locationId: $south->id));

        $this->assertContains('09:00', $northStarts);
        $this->assertNotContains('14:00', $northStarts);
        $this->assertContains('14:00', $southStarts);
        $this->assertNotContains('09:00', $southStarts);
    }

    public function test_an_inactive_sede_or_one_of_another_workspace_offers_nothing(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
        $inactive = $this->workspace->locations()->create(['name' => 'Cerrada', 'is_active' => false]);
        $foreign = $this->bookingWorkspace('OTRA', self::MODULES)->locations()->create(['name' => 'Ajena']);

        $this->assertSame([], $this->slots($service, locationId: $inactive->id));
        $this->assertSame([], $this->slots($service, locationId: $foreign->id));
    }

    public function test_the_rest_after_the_appointment_must_fit_inside_the_working_hours(): void
    {
        $service = $this->makeService($this->workspace, ['duration_minutes' => 45, 'buffer_minutes' => 10]);
        $this->makeProfessional($this->workspace, [$service]);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $this->slots($service));

        $this->assertContains('16:00', $starts);     // 16:00 + 45 + 10 = 16:55, inside 17:00
        $this->assertNotContains('16:15', $starts);  // attention would end 17:00 but the rest would run past the end of the shift
        $this->assertNotContains('16:30', $starts);
    }

    public function test_the_rest_never_runs_into_an_exception_and_is_never_cut_short(): void
    {
        $service = $this->makeService($this->workspace, ['duration_minutes' => 45, 'buffer_minutes' => 10]);
        $pro = $this->makeProfessional($this->workspace, [$service]);
        $this->workspace->scheduleExceptions()->create(['professional_id' => $pro->id, 'date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'starts_at' => '12:00:00', 'ends_at' => '13:00:00', 'reason' => 'Almuerzo']);

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $this->slots($service));

        $this->assertContains('11:00', $starts);     // 11:00-11:45 + rest 11:55
        $this->assertNotContains('11:15', $starts);  // attention ends 12:00 but the rest would run into the 12:00 block
    }

    public function test_a_second_appointment_cannot_start_inside_the_rest_of_the_first(): void
    {
        $service = $this->makeService($this->workspace, ['duration_minutes' => 45, 'buffer_minutes' => 10]);
        $pro = $this->makeProfessional($this->workspace, [$service]);
        $this->appointments()->book($this->workspace, $service, $pro->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), ['name' => 'Cliente', 'phone' => '+573001112233', 'email' => null], 'admin');

        $starts = array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $this->slots($service));

        $this->assertNotContains('10:45', $starts); // 10:00 + 45 minutes of attention is 10:45, but the pro is busy resting until 10:55
        $this->assertContains('11:00', $starts);    // the first step after 10:55
    }
}
