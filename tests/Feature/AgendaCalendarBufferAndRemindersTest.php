<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Location;
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
 * Block 4. The "time between appointments" of a service is a REST AFTER each appointment of that service (`blocks_until` = end + buffer, stored on the appointment
 * when it is booked): the single availability calculator offers and accepts the same starts to the agenda, the public page and the assistant, and a change of the
 * setting never rewrites what was already booked. The Agenda lists/filters by location, professional and service, and a member limited to selected locations only
 * ever gets their own. The reminders and confirmations live on their own tab but read and write the SAME settings route as before.
 * (Row locks and the PostgreSQL exclusion constraints behind simultaneous requests can not be proven in SQLite.)
 */
class AgendaCalendarBufferAndRemindersTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const MODULES = ['dashboard', 'service_catalog', 'professionals', 'bookings', 'locations'];

    private Workspace $ws;

    private Location $norte;

    private Location $sur;

    private Service $consulta;

    private Service $masaje;

    private User $admin;

    private User $limited;

    protected function setUp(): void
    {
        parent::setUp();

        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->ws = $this->bookingWorkspace('CLINICA', self::MODULES);
        $this->norte = $this->ws->locations()->create(['name' => 'Norte', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->sur = $this->ws->locations()->create(['name' => 'Sur', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $this->consulta = $this->makeService($this->ws, ['name' => 'Consulta', 'duration_minutes' => 30, 'buffer_minutes' => 15]);
        $this->masaje = $this->makeService($this->ws, ['name' => 'Masaje', 'duration_minutes' => 60, 'buffer_minutes' => 0]);
        $this->admin = $this->member($this->ws, 'admin');
        $this->limited = $this->member($this->ws, 'admin');
        app(LocationScope::class)->save($this->ws, $this->limited, 'selected', [$this->norte->id]);
    }

    private function as(User $user, ?Workspace $workspace = null): void
    {
        $this->actingAs($user)->withSession(['workspace_id' => ($workspace ?? $this->ws)->id]);
    }

    private function day(string $date = '2026-10-13'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    /** @return list<string> */
    private function starts(Service $service, ?int $professionalId = null, ?int $locationId = null): array
    {
        return array_map(fn (array $slot) => $slot['starts_at']->format('H:i'), $this->calculator()->slots($this->ws, $service, $professionalId, $this->day(), $this->day()->addDay(), null, true, $locationId));
    }

    private function book(Service $service, $professional, string $start, ?Location $location = null): Appointment
    {
        return $this->appointments()->book($this->ws, $service, $professional->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Cliente '.$start, 'phone' => '+57300'.random_int(1000000, 9999999), 'email' => null], 'admin', null, null, ($location ?? $this->norte)->id)['appointment'];
    }

    // --- the time between appointments -----------------------------------------------------------------------------------------

    public function test_the_buffer_is_a_rest_after_the_appointment_of_that_service_and_blocks_real_time(): void
    {
        $pro = $this->makeProfessional($this->ws, [$this->consulta, $this->masaje]);
        $booked = $this->book($this->consulta, $pro, '2026-10-13 10:00:00'); // 10:00-10:30 + 15 min of rest

        $this->assertSame('10:45', $booked->blocks_until->format('H:i'), 'what the appointment really occupies');
        $starts = $this->starts($this->consulta, $pro->id);

        $this->assertNotContains('10:30', $starts); // inside the rest
        $this->assertNotContains('10:15', $starts);
        $this->assertContains('10:45', $starts);    // the rest is over
        $this->assertContains('09:15', $starts);    // ends 09:45 and its own rest reaches 10:00: touching is allowed
        $this->assertNotContains('09:30', $starts); // its own rest would run into the booked one

        // The agenda accepts exactly what it offers: the same slot is refused when asked for.
        $this->as($this->admin);
        $payload = fn (string $at) => ['service_id' => $this->consulta->id, 'professional_id' => $pro->id, 'location_id' => $this->norte->id, 'starts_at' => $this->day()->setTimeFromTimeString($at)->format('Y-m-d\TH:i:s\Z'), 'customer' => ['name' => 'Otra', 'phone' => '+573001234567']];
        $this->post('/bookings', $payload('10:30'))->assertSessionHas('error');
        $this->post('/bookings', $payload('10:45'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(2, Appointment::where('professional_id', $pro->id)->count());
    }

    public function test_services_with_different_durations_and_buffers_block_each_other_by_what_each_one_occupies(): void
    {
        $pro = $this->makeProfessional($this->ws, [$this->consulta, $this->masaje]);
        $this->book($this->consulta, $pro, '2026-10-13 10:00:00'); // blocks until 10:45

        $masaje = $this->starts($this->masaje, $pro->id);
        $this->assertNotContains('10:30', $masaje);
        $this->assertContains('10:45', $masaje);
        $this->assertContains('09:00', $masaje);  // a 60 minute massage ending exactly at 10:00 (no rest)
        $this->assertNotContains('09:15', $masaje);

        $this->book($this->masaje, $pro, '2026-10-13 11:00:00'); // 11:00-12:00, no rest
        $consulta = $this->starts($this->consulta, $pro->id);
        $this->assertContains('12:00', $consulta);
        $this->assertNotContains('10:45', $consulta); // 45 minutes (30 + 15 of rest) do not fit between 10:45 and the massage at 11:00
    }

    public function test_changing_the_buffer_never_rewrites_what_was_already_booked_and_applies_to_the_next_bookings(): void
    {
        $pro = $this->makeProfessional($this->ws, [$this->consulta]);
        $old = $this->book($this->consulta, $pro, '2026-10-13 10:00:00');
        $this->assertSame('10:45', $old->blocks_until->format('H:i'));

        $this->consulta->update(['buffer_minutes' => 60]);

        $this->assertSame('10:45', $old->fresh()->blocks_until->format('H:i'), 'the booked appointment keeps the rest it was booked with');
        $new = $this->book($this->consulta, $pro, '2026-10-13 12:00:00');
        $this->assertSame('13:30', $new->blocks_until->format('H:i'), 'the next booking uses the new setting');
        $starts = $this->starts($this->consulta, $pro->id);
        $this->assertNotContains('13:00', $starts);
        $this->assertContains('13:30', $starts);
    }

    public function test_the_agenda_the_public_page_and_the_staff_search_offer_the_same_starts(): void
    {
        $pro = $this->makeProfessional($this->ws, [$this->consulta]);
        $this->book($this->consulta, $pro, '2026-10-13 10:00:00');
        $key = $this->ws->bookingSetting()->updateOrCreate([], ['public_enabled' => true, 'min_notice_minutes' => 0, 'max_advance_days' => 60])->ensurePublicKey();

        $public = $this->getJson("/api/booking/{$key}/availability?service_id={$this->consulta->id}&professional_id={$pro->id}&location_id={$this->norte->id}&from=2026-10-13&days=1")->assertOk()->json('slots');
        $this->as($this->admin);
        $staff = $this->getJson("/bookings/availability?service_id={$this->consulta->id}&professional_id={$pro->id}&location_id={$this->norte->id}&from=2026-10-13&days=1")->assertOk()->json('slots');

        $labels = fn (array $slots) => collect($slots)->pluck('startsAt')->map(fn ($s) => substr((string) $s, 11, 5))->all();
        $this->assertNotEmpty($labels($public));
        $this->assertSame($labels($public), $labels($staff));
        $this->assertNotContains('10:30', $labels($public));
        $this->assertNotContains('10:30', $labels($staff));
    }

    public function test_the_same_slot_can_not_be_booked_twice_by_the_agenda_and_the_public_page(): void
    {
        $pro = $this->makeProfessional($this->ws, [$this->consulta]);
        $key = $this->ws->bookingSetting()->updateOrCreate([], ['public_enabled' => true, 'min_notice_minutes' => 0, 'max_advance_days' => 60])->ensurePublicKey();
        $body = ['service_id' => $this->consulta->id, 'professional_id' => $pro->id, 'location_id' => $this->norte->id, 'starts_at' => '2026-10-13T11:00:00Z', 'customer' => ['name' => 'Web', 'phone' => '+573001112233', 'email' => 'w@example.com']];

        $this->postJson("/api/booking/{$key}/appointments", $body)->assertCreated();
        $this->postJson("/api/booking/{$key}/appointments", ['customer' => ['phone' => '+573005556677', 'name' => 'Otra']] + $body)->assertStatus(409);
        $this->as($this->admin);
        $this->post('/bookings', ['starts_at' => '2026-10-13T11:20:00Z', 'location_id' => $this->norte->id, 'customer' => ['name' => 'Staff', 'phone' => '+573009998877']] + $body)->assertSessionHas('error');
        $this->assertSame(1, Appointment::where('professional_id', $pro->id)->count());
    }

    // --- calendar: window, filters, scope ------------------------------------------------------------------------------------------

    private function seedAppointments(): array
    {
        $ana = $this->makeProfessional($this->ws, [$this->consulta, $this->masaje], 'Ana');
        $beto = $this->makeProfessional($this->ws, [$this->consulta], 'Beto');
        $a = $this->book($this->consulta, $ana, '2026-10-13 10:00:00', $this->norte);
        $b = $this->book($this->masaje, $ana, '2026-10-13 12:00:00', $this->sur);
        $c = $this->book($this->consulta, $beto, '2026-10-14 10:00:00', $this->sur);
        $d = $this->book($this->consulta, $beto, '2026-10-20 10:00:00', $this->norte); // next week

        return compact('ana', 'beto', 'a', 'b', 'c', 'd');
    }

    private function listed(string $query)
    {
        return $this->get('/bookings?tab=agenda&'.$query);
    }

    public function test_the_day_and_the_week_show_their_own_appointments_and_filters_combine(): void
    {
        $x = $this->seedAppointments();
        $this->as($this->admin);
        $assertIds = function (string $query, array $expected) {
            $this->listed($query)->assertOk()->assertInertia(fn ($page) => $page->where('appointments', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
        };

        $assertIds('from=2026-10-13&days=1', [$x['a']->id, $x['b']->id]);                                             // day
        $assertIds('from=2026-10-13&days=7', [$x['a']->id, $x['b']->id, $x['c']->id]);                                // week
        $assertIds('from=2026-10-13&days=7&location='.$this->sur->id, [$x['b']->id, $x['c']->id]);                  // + sede
        $assertIds('from=2026-10-13&days=7&service='.$this->consulta->id, [$x['a']->id, $x['c']->id]);              // + servicio
        $assertIds('from=2026-10-13&days=7&professional='.$x['beto']->id, [$x['c']->id]);                            // + profesional
        $assertIds('from=2026-10-13&days=7&location='.$this->sur->id.'&service='.$this->consulta->id, [$x['c']->id]); // combined
        $assertIds('from=2026-10-13&days=7&location='.$this->norte->id.'&professional='.$x['beto']->id, []);          // nothing matches: an empty state, not an error
        $assertIds('from=2026-10-13&to=2026-10-20', [$x['a']->id, $x['b']->id, $x['c']->id, $x['d']->id]);          // custom range
        $this->listed('from=2026-10-13&days=7&location='.$this->sur->id.'&service='.$this->consulta->id)->assertInertia(fn ($page) => $page->where('filters.location', $this->sur->id)->where('filters.service', $this->consulta->id)->where('filters.days', 7)->where('listLimit', 500));
    }

    public function test_a_limited_member_only_gets_their_locations_whatever_the_filters_say(): void
    {
        $x = $this->seedAppointments();
        $this->as($this->limited);
        $assertIds = function (string $query, array $expected) {
            $this->listed($query)->assertOk()->assertInertia(fn ($page) => $page->where('appointments', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
        };

        $assertIds('from=2026-10-13&days=7', [$x['a']->id]);                                                  // no filter: only Norte
        $assertIds('from=2026-10-13&days=7&location='.$this->sur->id, []);                                    // manipulated filter: nothing of Sur
        $assertIds('from=2026-10-13&days=7&location=999999', []);
        $assertIds('from=2026-10-13&days=7&service='.$this->masaje->id, []);                                  // the only Masaje is in Sur
        $assertIds('from=2026-10-13&days=7&location='.$this->norte->id, [$x['a']->id]);
        $assertIds('from=2026-10-13&days=7&professional='.$x['beto']->id.'&location='.$this->sur->id, []);
    }

    public function test_the_filters_offer_only_what_exists_together_and_only_the_locations_the_member_may_consult(): void
    {
        $ana = $this->makeProfessional($this->ws, [$this->consulta], 'Ana');           // hours in every location
        $beto = $this->makeProfessional($this->ws, [$this->masaje], 'Beto');
        $beto->scheduleRules()->delete();
        $beto->scheduleRules()->create(['workspace_id' => $this->ws->id, 'weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00', 'location_id' => $this->sur->id]);
        $this->consulta->locations()->sync([$this->norte->id => ['workspace_id' => $this->ws->id]]);

        $this->as($this->admin);
        $this->get('/bookings')->assertInertia(fn ($page) => $page
            ->where('options.locations', fn ($rows) => collect($rows)->pluck('name')->all() === ['Norte', 'Sur'])
            ->where('options.services', fn ($rows) => collect($rows)->firstWhere('name', 'Consulta')['locationIds'] === [$this->norte->id] && collect($rows)->firstWhere('name', 'Masaje')['everywhere'] === true)
            ->where('options.professionals', fn ($rows) => collect($rows)->firstWhere('name', 'Beto')['locationIds'] === [$this->sur->id] && collect($rows)->firstWhere('name', 'Ana')['everywhere'] === true && collect($rows)->firstWhere('name', 'Beto')['serviceIds'] === [$this->masaje->id]));

        // The member limited to Norte: one location (the page shows no selector), and not the professional who only works in Sur.
        $this->as($this->limited);
        $this->get('/bookings')->assertInertia(fn ($page) => $page
            ->where('options.locations', fn ($rows) => collect($rows)->pluck('name')->all() === ['Norte'])
            ->where('options.professionals', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ana']));
    }

    public function test_the_calendar_never_mixes_workspaces_even_with_foreign_ids_in_the_filters(): void
    {
        $this->seedAppointments();
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        $oLocation = $other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $oService = $this->makeService($other, ['name' => 'Ajeno']);
        $oPro = $this->makeProfessional($other, [$oService], 'Zoe');
        $this->appointments()->book($other, $oService, $oPro->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), ['name' => 'Otro', 'phone' => '+573001230000', 'email' => null], 'admin', null, null, $oLocation->id);

        $this->as($this->admin);
        $foreign = ['location' => $oLocation->id, 'service' => $oService->id, 'professional' => $oPro->id];
        $this->listed('from=2026-10-13&days=7&'.http_build_query($foreign))->assertOk()->assertInertia(fn ($page) => $page->has('appointments', 0));
        $this->listed('from=2026-10-13&days=7')->assertInertia(fn ($page) => $page->where('appointments', fn ($rows) => collect($rows)->every(fn ($r) => in_array($r['service'], ['Consulta', 'Masaje'], true)) && count($rows) === 3));

        // A foreign appointment id is not reachable from this Workspace.
        $theirs = Appointment::where('workspace_id', $other->id)->firstOrFail();
        $this->post("/bookings/{$theirs->id}/cancel", [])->assertNotFound();
    }

    public function test_the_public_page_refuses_a_location_of_another_workspace_and_an_unauthorized_member_can_not_book_there(): void
    {
        $pro = $this->makeProfessional($this->ws, [$this->consulta]);
        $other = $this->bookingWorkspace('OTRA', self::MODULES);
        $oLocation = $other->locations()->create(['name' => 'Ajena', 'address' => 'x', 'city' => 'y', 'is_active' => true]);
        $key = $this->ws->bookingSetting()->updateOrCreate([], ['public_enabled' => true, 'min_notice_minutes' => 0, 'max_advance_days' => 60])->ensurePublicKey();

        $res = $this->postJson("/api/booking/{$key}/appointments", ['service_id' => $this->consulta->id, 'professional_id' => $pro->id, 'location_id' => $oLocation->id, 'starts_at' => '2026-10-13T11:00:00Z', 'customer' => ['name' => 'Web', 'phone' => '+573001112233', 'email' => 'w@example.com']]);
        $this->assertContains($res->status(), [404, 409, 422]);
        $this->assertSame(0, Appointment::count());

        $this->as($this->limited);
        $this->post('/bookings', ['service_id' => $this->consulta->id, 'professional_id' => $pro->id, 'location_id' => $this->sur->id, 'starts_at' => '2026-10-13T11:00:00Z', 'customer' => ['name' => 'Xavier', 'phone' => '+573001112233']])->assertForbidden();
        $this->assertSame(0, Appointment::count());
    }

    // --- reminders and confirmations: their own tab, the same settings --------------------------------------------------------

    public function test_the_reminders_settings_keep_one_source_and_the_same_permissions_on_their_new_tab(): void
    {
        $this->as($this->admin);
        $props = $this->get('/bookings?tab=recordatorios')->assertOk()->viewData('page')['props'];
        $this->assertArrayHasKey('notifications', $props['settings'], 'the tab reads the same notification settings the page always sent');
        $this->assertCount(1, array_filter(array_keys($props['settings']), fn ($k) => $k === 'notifications'));

        $this->assertCount(1, array_filter(app('router')->getRoutes()->getRoutes(), fn ($r) => str_contains($r->uri(), 'notifications') && str_starts_with($r->uri(), 'bookings')), 'one route writes them (no second one was added)');

        // Same authorization as before: a role that reads Agenda but may not manage its settings is refused by the server.
        Role::findOrCreate('lector', 'web')->syncPermissions(['view-bookings']);
        $this->as($this->member($this->ws, 'lector'));
        $this->get('/bookings?tab=recordatorios')->assertOk();
        $this->put('/bookings/settings/notifications', ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => false])->assertForbidden();

        // The administrator saves them through that same route and they persist for the tab.
        $this->as($this->admin);
        $this->put('/bookings/settings/notifications', ['notify_received' => false, 'notify_confirmed' => false, 'reminders_enabled' => false, 'reminder_offsets' => []])->assertSessionHasNoErrors();
        $this->get('/bookings?tab=recordatorios')->assertInertia(fn ($page) => $page->has('settings.notifications'));
    }
}
