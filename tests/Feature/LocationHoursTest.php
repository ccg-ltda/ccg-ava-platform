<?php

namespace Tests\Feature;

use App\Booking\Locations;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The opening hours of a sede. Configured in Sedes, by weekday (one interval per open day; a day without one is closed), saved by the server, drawn by the Agenda and enforced by the
 * ONE availability calculator, so what is offered and what is accepted cannot disagree: the whole occupation (attention + rest) must fit between opening and closing, intersected with the
 * professional's own hours. A sede without hours is not restricted and nothing is assumed for it. "Now" is Monday 2026-10-12 08:00 UTC; 2026-10-13 is a Tuesday (weekday 2).
 */
class LocationHoursTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations'];

    private Workspace $workspace;

    private Service $service;

    private Professional $pro;

    private Location $norte;

    private Location $sur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        $this->service = $this->makeService($this->workspace, ['name' => 'Corte', 'duration_minutes' => 30, 'buffer_minutes' => 10]);
        $this->pro = $this->makeProfessional($this->workspace, [$this->service]); // Monday to Friday 09:00-17:00, every sede
        $this->norte = $this->workspace->locations()->create(['name' => 'Norte']);
        $this->sur = $this->workspace->locations()->create(['name' => 'Sur']);
        $this->actAsMember($this->workspace);
    }

    private function hours(Location $location, array $days): void
    {
        foreach ($days as $weekday => [$opens, $closes]) {
            DB::table('location_hours')->insert(['workspace_id' => $location->workspace_id, 'location_id' => $location->id, 'weekday' => $weekday, 'opens_at' => $opens.':00', 'closes_at' => $closes.':00', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return list<string> */
    private function starts(Location $location, string $date = '2026-10-13'): array
    {
        $day = CarbonImmutable::parse($date, 'UTC');

        return array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $this->calculator()->slots($this->workspace, $this->service, null, $day, $day->addDay(), locationId: $location->id));
    }

    private function book(Location $location, string $startsAt)
    {
        return $this->post('/bookings', ['service_id' => $this->service->id, 'location_id' => $location->id, 'starts_at' => $startsAt, 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233']]);
    }

    // --- configuring -------------------------------------------------------------------------------------------------

    public function test_the_hours_are_saved_by_weekday_shown_back_and_each_sede_keeps_its_own(): void
    {
        $this->put("/locations/{$this->norte->id}", ['name' => 'Norte', 'offers_all_services' => true, 'has_hours' => true, 'hours' => [
            ['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '18:00'], ['weekday' => 2, 'opens_at' => '08:00', 'closes_at' => '18:00'], ['weekday' => 6, 'opens_at' => '09:00', 'closes_at' => '13:00'],
        ]])->assertSessionHasNoErrors();
        $this->put("/locations/{$this->sur->id}", ['name' => 'Sur', 'offers_all_services' => true, 'has_hours' => true, 'hours' => [['weekday' => 2, 'opens_at' => '07:00', 'closes_at' => '17:00']]])->assertSessionHasNoErrors();

        $weekly = app(Locations::class)->weeklyHours($this->workspace, [$this->norte->id, $this->sur->id]);
        $this->assertSame([1 => [480, 1080], 2 => [480, 1080], 6 => [540, 780]], $weekly[$this->norte->id]);
        $this->assertSame([2 => [420, 1020]], $weekly[$this->sur->id]);

        $this->get('/locations')->assertInertia(fn ($page) => $page
            ->where('locations.0.name', 'Norte')->where('locations.0.hours.0', ['weekday' => 1, 'opensAt' => '08:00', 'closesAt' => '18:00'])->has('locations.0.hours', 3)
            ->where('locations.1.hours.0.opensAt', '07:00'));
        $this->assertTrue(AuditLog::where('resource_type', 'location')->where('resource_id', $this->sur->id)->get()->contains(fn ($row) => str_contains(json_encode($row->changes, JSON_UNESCAPED_UNICODE), 'Horario de atención') && str_contains(json_encode($row->changes), '07:00-17:00')), 'the change of hours is audited');
    }

    public function test_editing_one_day_replaces_the_set_and_turning_the_hours_off_clears_them_without_touching_anything_else(): void
    {
        $this->hours($this->norte, [1 => ['08:00', '18:00'], 2 => ['08:00', '18:00']]);
        $this->hours($this->sur, [2 => ['07:00', '17:00']]);

        $this->put("/locations/{$this->norte->id}", ['name' => 'Norte', 'offers_all_services' => true, 'has_hours' => true, 'hours' => [['weekday' => 2, 'opens_at' => '10:00', 'closes_at' => '12:00']]])->assertSessionHasNoErrors();
        $weekly = app(Locations::class)->weeklyHours($this->workspace, [$this->norte->id, $this->sur->id]);
        $this->assertSame([2 => [600, 720]], $weekly[$this->norte->id], 'Monday is now closed: the unchecked day is gone');
        $this->assertSame([2 => [420, 1020]], $weekly[$this->sur->id], 'another sede is untouched');

        // A form that says nothing about hours leaves them as they are; has_hours=false clears them (no hours configured: not restricted).
        $this->put("/locations/{$this->norte->id}", ['name' => 'Norte', 'offers_all_services' => true])->assertSessionHasNoErrors();
        $this->assertNotNull(app(Locations::class)->weeklyHours($this->workspace, [$this->norte->id])[$this->norte->id]);
        $this->put("/locations/{$this->norte->id}", ['name' => 'Norte', 'offers_all_services' => true, 'has_hours' => false])->assertSessionHasNoErrors();
        $this->assertNull(app(Locations::class)->weeklyHours($this->workspace, [$this->norte->id])[$this->norte->id]);
    }

    public function test_invalid_hours_are_refused_by_the_server(): void
    {
        $send = fn (array $hours, bool $has = true) => $this->put("/locations/{$this->norte->id}", ['name' => 'Norte', 'offers_all_services' => true, 'has_hours' => $has, 'hours' => $hours]);

        $send([['weekday' => 2, 'opens_at' => '18:00', 'closes_at' => '08:00']])->assertSessionHasErrors('hours');
        $send([['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '09:00']])->assertSessionHasErrors('hours');
        $send([])->assertSessionHasErrors('hours');                                        // hours on, no open day
        $send([['weekday' => 8, 'opens_at' => '09:00', 'closes_at' => '10:00']])->assertSessionHasErrors('hours.0.weekday');
        $send([['weekday' => 2, 'opens_at' => '9am', 'closes_at' => '10:00']])->assertSessionHasErrors('hours.0.opens_at');
        $send([['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '10:00'], ['weekday' => 2, 'opens_at' => '11:00', 'closes_at' => '12:00']])->assertSessionHasErrors();

        $this->assertNull(app(Locations::class)->weeklyHours($this->workspace, [$this->norte->id])[$this->norte->id], 'nothing was saved');
    }

    public function test_a_new_sede_can_be_created_with_its_hours_and_only_who_may_edit_sedes_can_set_them(): void
    {
        $this->post('/locations', ['name' => 'Centro', 'offers_all_services' => true, 'has_hours' => true, 'hours' => [['weekday' => 3, 'opens_at' => '09:00', 'closes_at' => '22:00']]])->assertSessionHasNoErrors();
        $centro = $this->workspace->locations()->where('name', 'Centro')->firstOrFail();
        $this->assertSame([3 => [540, 1320]], app(Locations::class)->weeklyHours($this->workspace, [$centro->id])[$centro->id]);

        $this->actAsMember($this->workspace, 'cliente');
        $this->put("/locations/{$centro->id}", ['name' => 'Centro', 'offers_all_services' => true, 'has_hours' => false])->assertForbidden();
        $this->assertNotNull(app(Locations::class)->weeklyHours($this->workspace, [$centro->id])[$centro->id]);
    }

    public function test_another_workspace_can_neither_read_nor_write_the_hours_of_a_sede_that_is_not_its_own(): void
    {
        $other = $this->bookingWorkspace('OTRO', self::MODULES);
        $theirs = $other->locations()->create(['name' => 'Ajena']);
        $this->hours($theirs, [2 => ['09:00', '17:00']]);

        $this->put("/locations/{$theirs->id}", ['name' => 'Ajena', 'offers_all_services' => true, 'has_hours' => false])->assertNotFound();
        $this->assertNotNull(app(Locations::class)->weeklyHours($other, [$theirs->id])[$theirs->id]);
        // Asked for from this Workspace, the ids of the other one give nothing.
        $this->assertSame([$theirs->id => null], app(Locations::class)->weeklyHours($this->workspace, [$theirs->id]));

        $this->expectException(QueryException::class);
        DB::table('location_hours')->insert(['workspace_id' => $this->workspace->id, 'location_id' => $theirs->id, 'weekday' => 4, 'opens_at' => '09:00:00', 'closes_at' => '10:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }

    // --- availability and the server ---------------------------------------------------------------------------------

    public function test_only_starts_whose_whole_occupation_fits_between_opening_and_closing_are_offered(): void
    {
        $this->hours($this->norte, [2 => ['10:00', '12:00']]);

        $starts = $this->starts($this->norte);

        $this->assertSame('10:00', $starts[0]);
        $this->assertSame('11:15', end($starts), '11:15 + 30 min of attention + 10 min of rest = 11:55, inside 12:00; 11:30 would end at 12:10');
        $this->assertNotContains('09:45', $starts, 'the professional works then, the sede is not open');
        $this->assertNotContains('11:30', $starts);

        // A sede with no hours is not restricted: the professional's own 09:00-17:00 decides, exactly as before.
        $open = $this->starts($this->sur);
        $this->assertSame(['09:00', '16:15'], [$open[0], end($open)]);
    }

    public function test_a_day_without_hours_is_closed_and_the_effective_time_is_the_intersection_with_the_professionals_hours(): void
    {
        $this->hours($this->norte, [2 => ['07:00', '22:00']]); // opens earlier and closes later than the professional works

        $this->assertSame([], $this->starts($this->norte, '2026-10-14'), 'Wednesday has no hours: closed');
        $starts = $this->starts($this->norte);
        $this->assertSame(['09:00', '16:15'], [$starts[0], end($starts)], 'the professional (09:00-17:00) still limits what the sede allows');

        // Open only 13:00-15:00 but the professional left a block 14:00-16:00: only what both allow.
        $this->workspace->scheduleExceptions()->create(['professional_id' => $this->pro->id, 'date_from' => '2026-10-13', 'date_to' => '2026-10-13', 'starts_at' => '14:00:00', 'ends_at' => '16:00:00', 'reason' => 'Bloqueo']);
        DB::table('location_hours')->where('location_id', $this->norte->id)->update(['opens_at' => '13:00:00', 'closes_at' => '15:00:00']);
        $this->assertSame(['13:00', '13:15'], $this->starts($this->norte), '13:15 ends its rest at 13:55; 13:30 would run into the 14:00 block');
    }

    public function test_the_server_refuses_a_forged_booking_outside_the_hours_and_accepts_one_inside(): void
    {
        $this->hours($this->norte, [2 => ['10:00', '12:00']]);

        $this->book($this->norte, '2026-10-13T09:00:00Z')->assertSessionHas('error');  // before opening
        $this->book($this->norte, '2026-10-13T11:30:00Z')->assertSessionHas('error');  // its rest would pass the closing
        $this->book($this->norte, '2026-10-13T12:00:00Z')->assertSessionHas('error');  // at closing
        $this->book($this->norte, '2026-10-14T10:00:00Z')->assertSessionHas('error');  // a closed day
        $this->assertSame(0, Appointment::count());

        $this->book($this->norte, '2026-10-13T11:15:00Z')->assertSessionHas('success');
        $this->assertSame(1, Appointment::count());
        // The conflict rules still apply inside the hours.
        $this->book($this->norte, '2026-10-13T11:15:00Z')->assertSessionHas('error');
    }

    public function test_changing_the_hours_later_never_rewrites_or_cancels_an_existing_appointment(): void
    {
        $this->hours($this->norte, [2 => ['10:00', '12:00']]);
        $this->book($this->norte, '2026-10-13T11:15:00Z')->assertSessionHas('success');
        $appointment = Appointment::firstOrFail();
        $before = $appointment->only(['starts_at', 'ends_at', 'blocks_until', 'status', 'location_id']);

        $this->put("/locations/{$this->norte->id}", ['name' => 'Norte', 'offers_all_services' => true, 'has_hours' => true, 'hours' => [['weekday' => 2, 'opens_at' => '08:00', 'closes_at' => '09:00']]])->assertSessionHasNoErrors();

        $this->assertEquals($before, $appointment->fresh()->only(['starts_at', 'ends_at', 'blocks_until', 'status', 'location_id']));
        $this->get('/bookings?from=2026-10-13&days=1')->assertInertia(fn ($page) => $page->has('appointments', 1));
        // The new hours decide what is offered from now on.
        $this->assertNotContains('11:15', $this->starts($this->norte));
    }

    public function test_the_agenda_receives_the_hours_of_each_sede_it_may_use_and_null_where_none_are_configured(): void
    {
        $this->hours($this->norte, [1 => ['08:00', '18:00'], 2 => ['08:00', '18:00']]);

        $this->get('/bookings')->assertInertia(fn ($page) => $page
            ->where('options.locationHours.'.$this->norte->id.'.1', [480, 1080])
            ->where('options.locationHours.'.$this->norte->id.'.2', [480, 1080])
            ->where('options.locationHours.'.$this->sur->id, null));
    }

    public function test_the_public_booking_page_and_the_reschedule_use_the_same_hours(): void
    {
        $this->hours($this->norte, [2 => ['10:00', '12:00']]);
        $this->book($this->norte, '2026-10-13T10:00:00Z')->assertSessionHas('success');
        $appointment = Appointment::firstOrFail();

        // Moving it outside the hours of ITS sede is refused by the same calculator.
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T15:00:00Z'])->assertSessionHas('error');
        $this->assertSame('2026-10-13 10:00:00', $appointment->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->post("/bookings/{$appointment->id}/reschedule", ['starts_at' => '2026-10-13T11:00:00Z'])->assertSessionHas('success');
    }
}
