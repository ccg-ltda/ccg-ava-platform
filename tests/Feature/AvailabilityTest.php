<?php

namespace Tests\Feature;

use App\Booking\BookingException;
use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** The availability calculator of Ava's own agenda: schedules, breaks, exceptions, buffers, resources, notice and isolation. */
class AvailabilityTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace();
    }

    private function day(string $date = '2026-10-13'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    /** @return list<string> the HH:MM starts the calculator offers on a day */
    private function starts($service, $professionalId = null, string $date = '2026-10-13', ?Workspace $workspace = null): array
    {
        return array_map(
            fn (array $slot) => $slot['starts_at']->format('H:i'),
            $this->calculator()->slots($workspace ?? $this->workspace, $service, $professionalId, $this->day($date), $this->day($date)->addDay()),
        );
    }

    private function book($service, $professional, string $start, ?Workspace $workspace = null): Appointment
    {
        $workspace ??= $this->workspace;

        return $this->appointments()->book($workspace, $service, $professional->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Cliente', 'phone' => '+573001112233', 'email' => null], 'admin')['appointment'];
    }

    public function test_it_offers_every_start_inside_the_weekly_schedule_that_fits_the_service(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);

        $starts = $this->starts($service);

        $this->assertSame('09:00', $starts[0]);
        $this->assertSame('16:00', end($starts)); // 60 minutes must end by 17:00
        $this->assertCount(29, $starts);          // every 15 minutes from 09:00 to 16:00
    }

    public function test_a_break_between_two_intervals_is_never_offered_and_a_service_cannot_straddle_it(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->workspace->professionals()->create(['name' => 'Ana']);
        $professional->services()->sync([$service->id => ['workspace_id' => $this->workspace->id]]);
        $professional->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '13:00:00']);
        $professional->scheduleRules()->create(['workspace_id' => $this->workspace->id, 'weekday' => 2, 'starts_at' => '14:00:00', 'ends_at' => '18:00:00']);

        $starts = $this->starts($service);

        $this->assertContains('12:00', $starts);
        $this->assertNotContains('12:15', $starts);
        $this->assertNotContains('13:00', $starts);
        $this->assertContains('14:00', $starts);
    }

    public function test_a_day_the_professional_does_not_work_has_no_starts(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service], weekdays: [1, 3]);

        $this->assertSame([], $this->starts($service, date: '2026-10-13')); // Tuesday
        $this->assertNotSame([], $this->starts($service, date: '2026-10-14')); // Wednesday
    }

    public function test_a_closed_day_a_vacation_range_and_a_blocked_hour_remove_starts(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service], weekdays: [1, 2, 3, 4, 5]);

        $this->workspace->scheduleExceptions()->create(['professional_id' => null, 'date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'reason' => 'Festivo']);
        $this->workspace->scheduleExceptions()->create(['professional_id' => $professional->id, 'date_from' => '2026-10-15', 'date_to' => '2026-10-16', 'reason' => 'Vacaciones']);
        $this->workspace->scheduleExceptions()->create(['professional_id' => $professional->id, 'date_from' => '2026-10-14', 'date_to' => '2026-10-14', 'starts_at' => '12:00:00', 'ends_at' => '14:00:00', 'reason' => 'Capacitación']);

        $this->assertSame([], $this->starts($service, date: '2026-10-13'));
        $this->assertSame([], $this->starts($service, date: '2026-10-15'));
        $this->assertSame([], $this->starts($service, date: '2026-10-16'));

        $wednesday = $this->starts($service, date: '2026-10-14');
        $this->assertContains('11:00', $wednesday);      // 11:00-12:00 ends where the block starts
        $this->assertNotContains('11:15', $wednesday);   // would run into the block
        $this->assertNotContains('13:00', $wednesday);
        $this->assertContains('14:00', $wednesday);
    }

    public function test_an_exception_of_one_professional_does_not_affect_another(): void
    {
        $service = $this->makeService($this->workspace);
        $ana = $this->makeProfessional($this->workspace, [$service], 'Ana');
        $beto = $this->makeProfessional($this->workspace, [$service], 'Beto');
        $this->workspace->scheduleExceptions()->create(['professional_id' => $ana->id, 'date_from' => '2026-10-13', 'date_to' => '2026-10-13']);

        $names = array_unique(array_map(fn (array $slot) => $slot['professional_name'], $this->calculator()->slots($this->workspace, $service, null, $this->day(), $this->day()->addDay())));

        $this->assertSame(['Beto'], array_values($names));
    }

    public function test_existing_appointments_and_the_buffer_block_the_time_they_occupy(): void
    {
        $service = $this->makeService($this->workspace, ['buffer_minutes' => 15]);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $this->book($service, $professional, '2026-10-13 11:00:00'); // occupies 11:00-12:15

        $starts = $this->starts($service, $professional->id);

        $this->assertContains('09:45', $starts);     // ends 10:45, its own buffer reaches 11:00: touching is allowed
        $this->assertNotContains('10:00', $starts);  // would overlap the 11:00 appointment with its buffer
        $this->assertNotContains('11:00', $starts);
        $this->assertNotContains('12:00', $starts);  // inside the buffer of the booked one
        $this->assertContains('12:15', $starts);
    }

    public function test_a_cancelled_appointment_frees_its_time(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $appointment = $this->book($service, $professional, '2026-10-13 10:00:00');
        $this->assertNotContains('10:00', $this->starts($service, $professional->id));

        $this->appointments()->cancel($appointment, null, 'admin');

        $this->assertContains('10:00', $this->starts($service, $professional->id));
    }

    public function test_the_notice_and_the_advance_window_of_the_workspace_limit_what_is_offered(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
        $this->workspace->bookingSetting()->create(['min_notice_minutes' => 180, 'max_advance_days' => 3]);

        $today = $this->starts($service, date: '2026-10-12');
        $this->assertSame('11:00', $today[0]); // now is 08:00, notice 3 h
        $this->assertSame([], $this->starts($service, date: '2026-10-20')); // beyond 3 days
    }

    public function test_staff_may_use_times_the_public_cannot(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $this->workspace->bookingSetting()->create(['min_notice_minutes' => 600]);
        $start = CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC');

        $this->assertNull($this->calculator()->slotAt($this->workspace, $service, $professional->id, $start));
        $this->assertNotNull($this->calculator()->slotAt($this->workspace, $service, $professional->id, $start, ignorePolicy: true));
    }

    public function test_inactive_services_and_professionals_and_professionals_who_do_not_perform_it_are_left_out(): void
    {
        $service = $this->makeService($this->workspace);
        $other = $this->makeService($this->workspace, ['name' => 'Pedicure']);
        $ana = $this->makeProfessional($this->workspace, [$service], 'Ana');
        $this->makeProfessional($this->workspace, [$other], 'Beto');

        $names = array_unique(array_map(fn (array $slot) => $slot['professional_name'], $this->calculator()->slots($this->workspace, $service, null, $this->day(), $this->day()->addDay())));
        $this->assertSame(['Ana'], array_values($names));

        $ana->update(['is_active' => false]);
        $this->assertSame([], $this->starts($service));

        $ana->update(['is_active' => true]);
        $service->update(['is_active' => false]);
        $this->assertSame([], $this->starts($service));
    }

    public function test_the_schedule_is_read_in_the_timezone_of_the_workspace(): void
    {
        $this->workspace->settings->update(['timezone' => 'America/Bogota']); // UTC-5
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);

        $slots = $this->calculator()->slots($this->workspace->fresh(), $service, null, $this->day(), $this->day()->addDay());

        $this->assertSame('14:00', $slots[0]['starts_at']->format('H:i')); // 09:00 in Bogota
    }

    public function test_a_resource_needed_by_the_service_must_be_free_and_two_resources_allow_two_at_once(): void
    {
        $service = $this->makeService($this->workspace);
        $chairA = $this->workspace->bookableResources()->create(['name' => 'Silla A']);
        $chairB = $this->workspace->bookableResources()->create(['name' => 'Silla B']);
        $service->resources()->sync([$chairA->id => ['workspace_id' => $this->workspace->id], $chairB->id => ['workspace_id' => $this->workspace->id]]);
        $ana = $this->makeProfessional($this->workspace, [$service], 'Ana');
        $beto = $this->makeProfessional($this->workspace, [$service], 'Beto');
        $carla = $this->makeProfessional($this->workspace, [$service], 'Carla');

        $first = $this->book($service, $ana, '2026-10-13 10:00:00');
        $second = $this->book($service, $beto, '2026-10-13 10:00:00');

        $this->assertNotSame($first->resource_id, $second->resource_id);
        $this->assertNotContains('10:00', $this->starts($service, $carla->id)); // both chairs are taken

        $this->expectException(BookingException::class);
        $this->book($service, $carla, '2026-10-13 10:00:00');
    }

    public function test_a_service_without_a_free_resource_is_not_offered_even_if_the_professional_is_free(): void
    {
        $service = $this->makeService($this->workspace);
        $chair = $this->workspace->bookableResources()->create(['name' => 'Silla A']);
        $service->resources()->sync([$chair->id => ['workspace_id' => $this->workspace->id]]);
        $this->makeProfessional($this->workspace, [$service]);
        $chair->update(['is_active' => false]);

        $this->assertSame([], $this->starts($service));
    }

    public function test_appointments_of_another_workspace_never_block_this_one(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $other = $this->bookingWorkspace('OTRO');
        $otherService = $this->makeService($other);
        $otherPro = $this->makeProfessional($other, [$otherService]);
        $this->book($otherService, $otherPro, '2026-10-13 10:00:00', $other);

        $this->assertContains('10:00', $this->starts($service, $professional->id));
        $this->assertSame([], array_filter($this->calculator()->slots($this->workspace, $service, null, $this->day(), $this->day()->addDay()), fn ($slot) => $slot['professional_id'] === $otherPro->id));
    }

    public function test_the_same_slot_cannot_be_booked_twice_and_without_naming_a_professional_the_next_free_one_is_used(): void
    {
        $service = $this->makeService($this->workspace);
        $ana = $this->makeProfessional($this->workspace, [$service], 'Ana');
        $beto = $this->makeProfessional($this->workspace, [$service], 'Beto');
        $start = CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC');
        $customer = ['name' => 'Cliente', 'phone' => '+573001112233', 'email' => null];

        $this->appointments()->book($this->workspace, $service, $ana->id, $start, $customer, 'admin');

        try {
            $this->appointments()->book($this->workspace, $service, $ana->id, $start, $customer, 'admin');
            $this->fail('A second booking of the same professional and time must be refused.');
        } catch (BookingException $exception) {
            $this->assertSame(BookingException::SLOT_UNAVAILABLE, $exception->reason);
        }

        $auto = $this->appointments()->book($this->workspace, $service, null, $start, ['name' => 'Otra', 'phone' => '+573002223344', 'email' => null], 'admin')['appointment'];

        $this->assertSame($beto->id, $auto->professional_id);
        $this->assertSame(2, Appointment::where('workspace_id', $this->workspace->id)->count());
    }

    public function test_a_start_the_agenda_never_offered_is_refused(): void
    {
        $service = $this->makeService($this->workspace);
        $ana = $this->makeProfessional($this->workspace, [$service]);

        foreach (['2026-10-13 03:00:00', '2026-10-13 09:07:00', '2026-10-17 10:00:00'] as $start) { // night, off the step, Saturday
            try {
                $this->appointments()->book($this->workspace, $service, $ana->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Cliente', 'phone' => '+573001112233', 'email' => null], 'web');
                $this->fail("{$start} must be refused");
            } catch (BookingException $exception) {
                $this->assertSame(BookingException::SLOT_UNAVAILABLE, $exception->reason, $start);
            }
        }
    }
}
