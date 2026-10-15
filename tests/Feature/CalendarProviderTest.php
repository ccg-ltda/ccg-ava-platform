<?php

namespace Tests\Feature;

use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\BookingRequest;
use App\Booking\CalendarProvider;
use App\Booking\CalendarProviders;
use App\Booking\InternalCalendar;
use App\Booking\ProviderException;
use App\Booking\UnavailableCalendar;
use App\Models\Appointment;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsBookings;
use Tests\Support\SimulatedCalendar;
use Tests\TestCase;

/**
 * The common contract of an agenda, run against Ava's own agenda and against a SIMULATED external one (it is not Booker), and what
 * Ava does around an external agenda: it stays the source of truth, nothing is confirmed that it did not confirm, a failure is a fixed
 * code, and the two agendas are never mixed. Booker itself is tested in BookerTest.
 */
class CalendarProviderTest extends TestCase
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

    private function manager(): BookingManager
    {
        return app(BookingManager::class);
    }

    private function booking(array $overrides = []): array
    {
        return $overrides + ['service_id' => 'S1', 'professional_id' => 'P1', 'starts_at' => CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), 'customer' => ['name' => 'Laura', 'phone' => '3001112233', 'email' => null]];
    }

    /** @return array<string, array{string}> */
    public static function agendas(): array
    {
        return ['Ava (internal)' => ['internal'], 'simulated external' => ['simulated']];
    }

    private function agenda(string $kind): CalendarProvider
    {
        if ($kind === 'internal') {
            $service = $this->makeService($this->workspace);
            $this->makeProfessional($this->workspace, [$service]);

            return new InternalCalendar($this->workspace, $this->calculator(), $this->appointments());
        }

        return new SimulatedCalendar;
    }

    // --- the contract ---------------------------------------------------------------------------------------------------

    #[DataProvider('agendas')]
    public function test_every_agenda_describes_its_capabilities_services_and_professionals_in_the_same_shape(string $kind): void
    {
        $agenda = $this->agenda($kind);

        $this->assertSame(['services', 'professionals', 'availability', 'book', 'reschedule', 'cancel', 'find'], array_keys($agenda->capabilities()));
        $this->assertContainsOnly('bool', $agenda->capabilities());

        foreach ($agenda->services() as $service) {
            $this->assertSame(['id', 'name', 'description', 'duration_minutes', 'price', 'category'], array_keys($service));
        }

        foreach ($agenda->professionals() as $professional) {
            $this->assertSame(['id', 'name', 'specialty'], array_keys($professional));
        }

        $this->assertNotEmpty($agenda->services());
        $this->assertNotEmpty($agenda->professionals());
    }

    #[DataProvider('agendas')]
    public function test_every_agenda_offers_slots_in_utc_that_it_then_accepts(string $kind): void
    {
        $agenda = $this->agenda($kind);
        $service = $agenda->services()[0];

        $slots = $agenda->availability($service['id'], null, CarbonImmutable::parse('2026-10-13', 'UTC'), CarbonImmutable::parse('2026-10-15', 'UTC'));

        $this->assertNotEmpty($slots);
        $slot = $slots[0];
        $this->assertSame(['starts_at', 'ends_at', 'professional_id', 'professional_name'], array_slice(array_keys($slot), 0, 4));
        $this->assertSame('UTC', $slot['starts_at']->timezoneName);
        $this->assertTrue($slot['ends_at'] > $slot['starts_at']);

        $result = $agenda->book(new BookingRequest($service['id'], $slot['professional_id'], $slot['starts_at'], ['name' => 'Laura', 'phone' => '3001112233', 'email' => null]));

        $this->assertContains($result->status, [Appointment::CONFIRMED, Appointment::REQUESTED]);
        $this->assertNotSame('', $result->externalId);
    }

    public function test_an_agenda_that_cannot_do_something_says_so_instead_of_pretending(): void
    {
        $agenda = new UnavailableCalendar('booker');

        $this->assertNotContains(true, $agenda->capabilities());

        foreach ([fn () => $agenda->services(), fn () => $agenda->availability(1, null, CarbonImmutable::now(), CarbonImmutable::now()), fn () => $agenda->book(new BookingRequest(1, null, CarbonImmutable::now(), ['name' => 'X', 'phone' => '1', 'email' => null]))] as $call) {
            try {
                $call();
                $this->fail('An unavailable agenda must refuse.');
            } catch (ProviderException $exception) {
                $this->assertSame(ProviderException::UNAVAILABLE, $exception->codeName);
            }
        }
    }

    // --- Ava's own agenda is not touched by a chosen external one ---------------------------------------------------------------

    public function test_choosing_an_external_agenda_that_is_not_connected_fails_closed_and_never_falls_back_to_the_internal_one(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
        $this->useBookerSetting($this->workspace); // no integration, no module

        $capabilities = $this->manager()->capabilities($this->workspace);

        $this->assertSame(['booker', true, false], [$capabilities['provider'], $capabilities['external'], $capabilities['connected']]);
        $this->assertInstanceOf(UnavailableCalendar::class, app(CalendarProviders::class)->for($this->workspace));

        foreach ([fn () => $this->manager()->services($this->workspace), fn () => $this->manager()->professionals($this->workspace)] as $call) {
            try {
                $call();
                $this->fail('A chosen but unavailable agenda must refuse.');
            } catch (BookingException $exception) {
                $this->assertSame(BookingException::PROVIDER, $exception->reason);
            }
        }
    }

    public function test_an_unavailable_agenda_refuses_to_book_and_creates_nothing_in_the_internal_agenda(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $this->useBookerSetting($this->workspace);

        try {
            $this->manager()->request($this->workspace, ['service_id' => $service->id, 'professional_id' => $professional->id, 'starts_at' => CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), 'customer' => ['name' => 'Laura', 'phone' => '3001112233', 'email' => null]], 'web');
            $this->fail('It must be refused.');
        } catch (BookingException $exception) {
            $this->assertSame(BookingException::UNSUPPORTED, $exception->reason);
        }

        $this->assertSame(0, Appointment::count());
    }

    // --- an external agenda stays the source of truth ------------------------------------------------------------------------------

    private function external(array $capabilities = []): SimulatedCalendar
    {
        $simulated = new SimulatedCalendar($capabilities + (new SimulatedCalendar)->capabilities);
        $this->useSimulatedAgenda($simulated);
        $this->useBookerSetting($this->workspace);

        return $simulated;
    }

    public function test_a_confirmed_external_booking_is_recorded_with_the_agenda_that_owns_it(): void
    {
        $simulated = $this->external();

        $result = $this->manager()->request($this->workspace, $this->booking(), 'web');
        $appointment = $result['appointment'];

        $this->assertNull($result['token']); // the link to manage it belongs to Ava's own agenda
        $this->assertSame(['booker', 'confirmed', 'synced', null], [$appointment->provider, $appointment->status, $appointment->sync_status, $appointment->sync_error]);
        $this->assertSame('1000', $appointment->external_id);
        $this->assertNull($appointment->service_id); // no row of Ava's own catalog is involved
        $this->assertSame('Manicure externa', $appointment->service_name);
        $this->assertSame(['services', 'professionals', 'book'], array_slice($simulated->calls, 0, 3));
    }

    public function test_a_booking_the_agenda_only_requested_is_not_shown_as_confirmed(): void
    {
        $simulated = $this->external();
        $simulated->bookStatus = Appointment::REQUESTED;

        $appointment = $this->manager()->request($this->workspace, $this->booking(), 'web')['appointment'];

        $this->assertSame('requested', $appointment->status);
        $this->assertSame('synced', $appointment->sync_status);
    }

    #[DataProvider('failures')]
    public function test_a_failed_external_booking_is_recorded_as_failed_with_a_fixed_code_and_never_as_confirmed(string $code, string $reason): void
    {
        $simulated = $this->external();
        $simulated->failWith['book'] = $code;

        try {
            $this->manager()->request($this->workspace, $this->booking(), 'chat');
            $this->fail('It must fail.');
        } catch (BookingException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        $appointment = Appointment::sole();
        // A refusal is certain: failed. A lost answer (timeout, unavailable) may have been processed: kept as a request of unknown outcome.
        $uncertain = (new ProviderException($code))->uncertain();
        $this->assertSame([$uncertain ? 'requested' : 'failed', $uncertain ? 'uncertain' : 'failed', $code, 'booker'], [$appointment->status, $appointment->sync_status, $appointment->sync_error, $appointment->provider]);
        $this->assertNull($appointment->external_id);
    }

    /** @return array<string, array{string, string}> */
    public static function failures(): array
    {
        return [
            'timeout' => [ProviderException::TIMEOUT, BookingException::UNCERTAIN],
            'credentials' => [ProviderException::AUTH, BookingException::PROVIDER],
            'rate limit' => [ProviderException::RATE_LIMITED, BookingException::PROVIDER],
            'unavailable' => [ProviderException::UNAVAILABLE, BookingException::UNCERTAIN],
            'conflict' => [ProviderException::CONFLICT, BookingException::SLOT_UNAVAILABLE],
        ];
    }

    public function test_a_booking_the_agenda_cannot_make_is_refused_before_anything_is_recorded(): void
    {
        $simulated = $this->external(['book' => false]);

        try {
            $this->manager()->request($this->workspace, $this->booking(), 'web');
            $this->fail('It must be refused.');
        } catch (BookingException $exception) {
            $this->assertSame(BookingException::UNSUPPORTED, $exception->reason);
        }

        $this->assertSame(0, Appointment::count());
        $this->assertNotContains('book', $simulated->calls);
    }

    public function test_cancelling_goes_through_the_agenda_and_a_refusal_leaves_the_appointment_active(): void
    {
        $simulated = $this->external();
        $appointment = $this->manager()->request($this->workspace, $this->booking(), 'admin')['appointment'];

        $simulated->failWith['cancel'] = ProviderException::UNAVAILABLE;
        try {
            $this->manager()->cancel($appointment, null, 'admin');
            $this->fail('The provider refused: the cancellation must fail.');
        } catch (BookingException) {
        }
        // The answer was lost: it may or may not be cancelled there. It stays active here, marked unknown (`refresh` asks the agenda).
        $this->assertSame(['confirmed', 'uncertain', 'unavailable'], [$appointment->fresh()->status, $appointment->fresh()->sync_status, $appointment->fresh()->sync_error]);

        unset($simulated->failWith['cancel']);
        $this->manager()->cancel($appointment->fresh(), 'Cambio de planes', 'admin');
        $this->assertSame(['cancelled', 'synced', null], [$appointment->fresh()->status, $appointment->fresh()->sync_status, $appointment->fresh()->sync_error]);
    }

    public function test_rescheduling_goes_through_the_agenda_or_is_refused_when_it_cannot(): void
    {
        $simulated = $this->external();
        $appointment = $this->manager()->request($this->workspace, $this->booking(), 'admin')['appointment'];
        $new = CarbonImmutable::parse('2026-10-14 15:00:00', 'UTC');

        $this->manager()->reschedule($appointment, $new, null, 'admin');
        $this->assertSame('2026-10-14 15:00:00', $appointment->fresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $appointment->fresh()->reschedule_count);

        $simulated->capabilities['reschedule'] = false;
        $this->expectException(BookingException::class);
        $this->manager()->reschedule($appointment->fresh(), $new->addDay(), null, 'admin');
    }

    public function test_an_external_appointment_is_confirmed_and_closed_in_its_own_agenda_not_in_ava(): void
    {
        $this->external();
        $appointment = $this->manager()->request($this->workspace, $this->booking(), 'admin')['appointment'];

        foreach ([Appointment::CONFIRMED, Appointment::COMPLETED, Appointment::NO_SHOW] as $to) {
            try {
                $this->manager()->transition($appointment, $to);
                $this->fail("{$to} must be refused for an external appointment");
            } catch (BookingException $exception) {
                $this->assertSame(BookingException::STATE, $exception->reason);
            }
        }
    }

    public function test_refreshing_aligns_what_the_agenda_cancelled_and_flags_what_it_no_longer_knows(): void
    {
        $simulated = $this->external();
        $appointment = $this->manager()->request($this->workspace, $this->booking(), 'admin')['appointment'];

        $simulated->bookings['1000'] = Appointment::CANCELLED;
        $this->manager()->refresh($appointment);
        $this->assertSame(['cancelled', 'synced'], [$appointment->fresh()->status, $appointment->fresh()->sync_status]);

        $other = $this->manager()->request($this->workspace, $this->booking(['starts_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC')]), 'admin')['appointment'];
        unset($simulated->bookings[$other->external_id]);
        $this->manager()->refresh($other);
        $this->assertSame(['failed', 'not_found'], [$other->fresh()->sync_status, $other->fresh()->sync_error]);
    }

    public function test_an_old_internal_appointment_stays_with_ava_when_the_workspace_moves_to_an_external_agenda(): void
    {
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $internal = $this->appointments()->book($this->workspace, $service, $professional->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), ['name' => 'Laura', 'phone' => '3001112233', 'email' => null], 'admin')['appointment'];
        $simulated = $this->external();

        $this->manager()->cancel($internal, null, 'admin');

        $this->assertSame('cancelled', $internal->fresh()->status);
        $this->assertNotContains('cancel', $simulated->calls); // the external agenda knows nothing of it
    }

    public function test_a_customer_cannot_change_a_booking_inside_the_notice_window_but_staff_can(): void
    {
        $this->external();
        $appointment = $this->manager()->request($this->workspace, $this->booking(['starts_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC')]), 'admin')['appointment']; // one hour from "now"

        try {
            $this->manager()->cancel($appointment, null, 'web');
            $this->fail('The customer may not cancel one hour before.');
        } catch (BookingException $exception) {
            $this->assertSame(BookingException::OUTSIDE_POLICY, $exception->reason);
        }

        $this->assertSame('cancelled', $this->manager()->cancel($appointment, null, 'admin')->status);
    }
}
