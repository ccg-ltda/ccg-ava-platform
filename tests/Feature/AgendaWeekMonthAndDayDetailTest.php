<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The Agenda calendar: the month shows one counter per day (an aggregate over the real appointments, the filters, the Workspace and the member's locations), the
 * detail of a day is read on demand and only returns what the same authorization allows, and the week/day views carry the real start, end and length of each
 * appointment. The price in the detail is the one recorded when it was booked, never a payment (Ava records none).
 */
class AgendaWeekMonthAndDayDetailTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $ws;

    private Location $norte;

    private Location $sur;

    private Service $consulta;

    private Professional $ana;

    private Professional $luis;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        $this->seedRoleCatalog();
        $this->freezeAgendaTime(); // Monday 2026-10-12 08:00 UTC
        $this->ws = $this->bookingWorkspace('CLINICA', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations']);
        $this->norte = $this->ws->locations()->create(['name' => 'Norte', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->sur = $this->ws->locations()->create(['name' => 'Sur', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->consulta = $this->makeService($this->ws, ['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 40000]);
        $this->ana = $this->makeProfessional($this->ws, [$this->consulta], 'Ana');
        $this->luis = $this->makeProfessional($this->ws, [$this->consulta], 'Luis');
        $this->admin = $this->member($this->ws, 'admin');
        $this->actingAs($this->admin)->withSession(['workspace_id' => $this->ws->id]);
    }

    private function book(Professional $professional, string $start, ?Location $location = null, ?Workspace $workspace = null, ?Service $service = null): Appointment
    {
        $workspace ??= $this->ws;

        return $this->appointments()->book($workspace, $service ?? $this->consulta, $professional->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Cliente '.$start, 'phone' => '+57300'.random_int(1000000, 9999999), 'email' => null], 'admin', null, null, ($location ?? $this->norte)->id)['appointment'];
    }

    /** Three appointments on the 13th (two of them with Ana), one on the 14th in Sur, one on the 20th. */
    private function populate(): void
    {
        $this->book($this->ana, '2026-10-13 11:00:00');
        $this->book($this->ana, '2026-10-13 09:00:00');
        $this->book($this->luis, '2026-10-13 09:00:00');
        $this->book($this->ana, '2026-10-14 10:00:00', $this->sur);
        $this->book($this->ana, '2026-10-20 15:30:00');
    }

    private function agenda(string $query): array
    {
        return $this->get('/bookings?tab=agenda&'.$query)->assertOk()->viewData('page')['props'];
    }

    // --- month counters ------------------------------------------------------------------------------------------------------------

    public function test_the_month_counters_match_the_real_appointments_of_each_day(): void
    {
        $this->populate();

        $props = $this->agenda('from=2026-10-01&days=31&view=calendar');

        $this->assertSame(['2026-10-13' => 3, '2026-10-14' => 1, '2026-10-20' => 1], $props['dayCounts']);
        $this->assertSame(Appointment::where('workspace_id', $this->ws->id)->count(), array_sum($props['dayCounts']));
        $this->assertSame('month', $props['filters']['period']);
        $this->assertSame('2026-10-01', $props['filters']['from']);
        $this->assertSame([], $props['appointments'], 'the calendar view of a month does not load every appointment: only the counts');
    }

    public function test_the_month_starts_on_the_first_day_whatever_day_was_asked_and_the_list_view_still_carries_the_appointments(): void
    {
        $this->populate();

        $props = $this->agenda('from=2026-10-17&days=31&view=list');

        $this->assertSame('2026-10-01', $props['filters']['from']);
        $this->assertCount(5, $props['appointments']);
        $this->assertSame(5, array_sum($props['dayCounts']));
    }

    public function test_the_counters_follow_the_active_filters(): void
    {
        $this->populate();

        $this->assertSame(['2026-10-14' => 1], $this->agenda('from=2026-10-01&days=31&view=calendar&location='.$this->sur->id)['dayCounts']);
        $this->assertSame(['2026-10-13' => 1], $this->agenda('from=2026-10-01&days=31&view=calendar&professional='.$this->luis->id)['dayCounts']);

        Appointment::where('professional_id', $this->luis->id)->update(['status' => Appointment::CANCELLED]);
        $this->assertSame(['2026-10-13' => 1], $this->agenda('from=2026-10-01&days=31&view=calendar&status=cancelled')['dayCounts']);
    }

    public function test_a_member_limited_to_one_location_only_counts_their_own_and_other_workspaces_never_count(): void
    {
        $this->populate();
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations']);
        $otherLocation = $other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $otherService = $this->makeService($other, ['name' => 'Ajeno']);
        $this->book($this->makeProfessional($other, [$otherService]), '2026-10-13 10:00:00', $otherLocation, $other, $otherService);

        $this->assertSame(['2026-10-13' => 3, '2026-10-14' => 1, '2026-10-20' => 1], $this->agenda('from=2026-10-01&days=31&view=calendar')['dayCounts'], 'the other Workspace is not in the count');

        $limited = $this->member($this->ws, 'admin');
        app(LocationScope::class)->save($this->ws, $limited, 'selected', [$this->norte->id]);
        $this->actingAs($limited)->withSession(['workspace_id' => $this->ws->id]);

        $this->assertSame(['2026-10-13' => 3, '2026-10-20' => 1], $this->agenda('from=2026-10-01&days=31&view=calendar')['dayCounts'], 'Sur is not theirs');
        // A forged filter can only return less, never another location.
        $this->assertSame([], $this->agenda('from=2026-10-01&days=31&view=calendar&location='.$this->sur->id)['dayCounts']);
    }

    // --- the detail of a day -------------------------------------------------------------------------------------------------------

    public function test_the_day_detail_lists_every_appointment_of_that_day_by_start_with_the_data_that_matter(): void
    {
        $this->populate();

        $day = $this->get('/bookings/day?date=2026-10-13')->assertOk()->json();

        $this->assertSame(['date' => '2026-10-13', 'total' => 3, 'truncated' => false], collect($day)->only(['date', 'total', 'truncated'])->all());
        $starts = collect($day['appointments'])->pluck('startsAt')->all();
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts, 'sorted by start time');
        $first = $day['appointments'][0];
        $this->assertSame('09:00 AM', $first['timeLabel']);
        $this->assertSame('09:30 AM', $first['endLabel']);
        $this->assertSame(30, $first['durationMinutes']);
        $this->assertSame('Consulta', $first['service']);
        $this->assertContains($first['professional'], ['Ana', 'Luis']);
        $this->assertStringStartsWith('Cliente ', $first['customer']['name']);
        $this->assertEquals(40000, $first['price'], 'the price recorded when it was booked');
        $this->assertSame(Appointment::LABELS[Appointment::CONFIRMED], $first['statusLabel']);
    }

    public function test_the_day_detail_has_the_same_scope_and_filters_as_the_counter(): void
    {
        $this->populate();

        $this->assertSame(1, $this->get('/bookings/day?date=2026-10-13&professional='.$this->luis->id)->json('total'));
        $this->assertSame(0, $this->get('/bookings/day?date=2026-10-15')->json('total'), 'a day without appointments is empty, not an error');

        $limited = $this->member($this->ws, 'admin');
        app(LocationScope::class)->save($this->ws, $limited, 'selected', [$this->norte->id]);
        $this->actingAs($limited)->withSession(['workspace_id' => $this->ws->id]);

        $this->assertSame(0, $this->get('/bookings/day?date=2026-10-14')->json('total'), 'the Sur appointment is not theirs');
        $this->assertSame(0, $this->get('/bookings/day?date=2026-10-14&location='.$this->sur->id)->json('total'));
        $this->assertSame(3, $this->get('/bookings/day?date=2026-10-13')->json('total'));
    }

    public function test_the_day_detail_never_returns_another_workspaces_appointments_and_needs_the_agenda_permission_and_module(): void
    {
        $this->populate();
        $other = $this->bookingWorkspace('OTRA', ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations']);
        $otherLocation = $other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $otherService = $this->makeService($other, ['name' => 'Ajeno']);
        $this->book($this->makeProfessional($other, [$otherService]), '2026-10-13 12:00:00', $otherLocation, $other, $otherService);

        $services = collect($this->get('/bookings/day?date=2026-10-13')->json('appointments'))->pluck('service')->unique()->all();
        $this->assertSame(['Consulta'], $services);

        $this->get('/bookings/day?date=nope')->assertStatus(422);
        $this->get('/bookings/day')->assertStatus(422);

        Role::findOrCreate('sin-agenda', 'web')->syncPermissions(['view-dashboard']);
        $blind = $this->member($this->ws, 'sin-agenda');
        $this->actingAs($blind)->withSession(['workspace_id' => $this->ws->id]);
        $this->get('/bookings/day?date=2026-10-13')->assertForbidden();

        $this->contractModules($this->ws, ['dashboard']);
        $this->actingAs($this->admin)->withSession(['workspace_id' => $this->ws->id]);
        $this->get('/bookings/day?date=2026-10-13')->assertForbidden();
    }

    // --- week and day views --------------------------------------------------------------------------------------------------------

    public function test_the_week_view_carries_the_real_start_end_and_length_of_each_appointment(): void
    {
        $this->book($this->ana, '2026-10-13 09:30:00');
        $this->consulta->update(['duration_minutes' => 60]);
        $long = $this->makeService($this->ws, ['name' => 'Masaje', 'duration_minutes' => 60, 'buffer_minutes' => 0]);
        $this->ana->services()->attach($long->id, ['workspace_id' => $this->ws->id]);
        $this->book($this->ana, '2026-10-13 13:00:00', null, null, $long);

        $props = $this->agenda('from=2026-10-12&days=7&view=calendar');
        $byService = collect($props['appointments'])->keyBy('service');

        $this->assertSame('week', $props['filters']['period']);
        $this->assertSame([9 * 60 + 30, 30, '09:30 AM', '10:00 AM'], [$byService['Consulta']['startMinute'], $byService['Consulta']['durationMinutes'], $byService['Consulta']['timeLabel'], $byService['Consulta']['endLabel']]);
        $this->assertSame([13 * 60, 60, '02:00 PM'], [$byService['Masaje']['startMinute'], $byService['Masaje']['durationMinutes'], $byService['Masaje']['endLabel']]);
        $this->assertNull($props['dayCounts'], 'the counters only exist for the month');
    }

    public function test_an_empty_week_and_an_empty_month_still_answer_so_the_calendar_can_draw_its_empty_grid(): void
    {
        $week = $this->agenda('from=2026-11-02&days=7&view=calendar');
        $this->assertSame([], $week['appointments']);
        $this->assertSame(['week', '2026-11-02'], [$week['filters']['period'], $week['filters']['from']]);

        $month = $this->agenda('from=2026-11-10&days=31&view=calendar');
        $this->assertSame([], $month['dayCounts']);
        $this->assertSame('2026-11-01', $month['filters']['from']);

        $day = $this->agenda('from=2026-11-04&days=1');
        $this->assertSame('day', $day['filters']['period']);
    }

    public function test_a_custom_range_stays_a_list_and_keeps_the_filters(): void
    {
        $this->populate();

        $props = $this->agenda('from=2026-10-13&to=2026-10-14&professional='.$this->ana->id);

        $this->assertSame('custom', $props['filters']['period']);
        $this->assertSame($this->ana->id, $props['filters']['professional']);
        $this->assertCount(3, $props['appointments']);
    }
}
