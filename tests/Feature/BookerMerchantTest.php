<?php

namespace Tests\Feature;

use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\ProviderException;
use App\Booking\PublicBooking;
use App\Integrations\IntegrationRegistry;
use App\Integrations\SafeHttpTarget;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Services\IntegrationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The Booker MERCHANT connector (treatments, customers, availability, create appointment), tested ONLY against responses SIMULATED by this file.
 * Booker's support confirmed the four endpoints and the flow, not the JSON shapes: the responses below encode the assumptions written in
 * BookerMerchantCalendar (A1 to A4), so a green test proves Ava's side (the requests it builds, that it re-reads availability before booking,
 * that it passes Booker's slot back untouched, never repeats a write, never confirms what Booker did not, keeps Workspaces and locations apart and
 * leaks no secret) and NOT that Booker answers like this. That is what the Staging test with the corporate credentials is for.
 */
class BookerMerchantTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const SECRETS = ['client_id' => 'CID-s3cret-1', 'client_secret' => 'CSEC-s3cret-2', 'subscription_key' => 'SUB-s3cret-3'];

    private const MODULES = ['dashboard', 'integrations', 'settings', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings', 'calendar_providers'];

    private Workspace $workspace;

    /** @var list<array{level: string, text: string}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        $this->resetHttp();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
        Log::listen(function (MessageLogged $event) {
            $this->logs[] = ['level' => $event->level, 'text' => $event->message.json_encode($event->context)];
        });
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
    }

    /** Connects the Workspace to the Merchant API with a main location (777) and two more. */
    private function connect(?Workspace $workspace = null, array $overrides = [], array $secrets = self::SECRETS): Integration
    {
        $workspace ??= $this->workspace;
        $integration = app(IntegrationService::class)->saveProvider($workspace, 'booker', $overrides + ['environment' => 'staging', 'api' => 'merchant', 'location_id' => '777', 'locations' => "888 | Sede Norte\n999 | Sede Sur"] + $secrets);
        $workspace->bookingSetting()->updateOrCreate([], ['provider' => 'booker']);

        return $integration;
    }

    private function calendar(Integration $integration)
    {
        return app(IntegrationRegistry::class)->get('booker')->calendar($integration);
    }

    private function manager(): BookingManager
    {
        return app(BookingManager::class);
    }

    private function token(string $token = 'tok-123'): array
    {
        return ['*/v5/auth/connect/token' => Http::response(['access_token' => $token, 'expires_in' => 3600])];
    }

    private function treatments(): array
    {
        return ['Treatments' => [
            ['ID' => 1, 'Name' => 'Manicure', 'Description' => 'D', 'TotalDuration' => 45, 'Price' => ['Amount' => 30, 'CurrencyCode' => 'USD'], 'IsActive' => true, 'IsClass' => false, 'AllowCustomersToBookOnline' => true],
            ['ID' => 2, 'Name' => 'Clase', 'IsActive' => true, 'IsClass' => true],
        ]];
    }

    /** What the simulated availability answers: one list with the slots (the raw values are kept so the test can prove they come back untouched). */
    private function slots(array $starts, int $treatment = 1, int $employee = 11): array
    {
        return ['ItineraryTimeSlotLists' => [['Slots' => array_map(fn (string $start) => [
            'StartDateTime' => $start, 'TreatmentTimeSlots' => [['TreatmentID' => $treatment, 'EmployeeID' => $employee, 'StartDateTime' => $start]],
        ], $starts), 'Token' => 'itinerary-opaque']]];
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['service_id' => '1', 'professional_id' => null, 'location_id' => 888, 'starts_at' => CarbonImmutable::parse('2026-10-13T10:00:00Z'), 'customer' => ['name' => 'Laura Pérez', 'phone' => '+573001112233', 'email' => 'laura@example.com']];
    }

    private function recorded(string $suffix)
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), $suffix))->map(fn ($pair) => $pair[0]);
    }

    // --- configuration: locations, environment, secrets ---------------------------------------------------------------------

    public function test_the_workspace_configures_its_locations_and_the_secrets_never_come_back(): void
    {
        $integration = $this->connect();

        $this->assertSame([['id' => '777', 'name' => 'Sede principal'], ['id' => '888', 'name' => 'Sede Norte'], ['id' => '999', 'name' => 'Sede Sur']], $integration->config['locations']);
        $this->assertSame('merchant', $integration->config['api']);
        $form = app(IntegrationRegistry::class)->get('booker')->form($integration);
        $this->assertSame("888 | Sede Norte\n999 | Sede Sur", $form['locations']);
        $this->assertStringNotContainsString('s3cret', json_encode($form));
        $this->assertSame([], array_filter(AuditLog::all()->map(fn ($log) => str_contains(json_encode($log->toArray()), 's3cret'))->all()));
    }

    public function test_bookers_test_location_is_never_accepted_for_production_and_bad_location_lists_are_refused(): void
    {
        foreach ([['location_id' => '3749'], ['locations' => '3749 | Pruebas'], ['location_id' => '3749', 'locations' => '']] as $input) {
            try {
                $this->connect(null, $input + ['environment' => 'production']);
                $this->fail('The shared test location must be refused in production.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('location_id', $e->errors());
            }
        }
        $this->assertSame(0, Integration::where('type', 'booker')->count());

        // The same ID is fine against Staging, which is what it is for.
        $this->assertSame('3749', $this->connect(null, ['location_id' => '3749'])->config['location_id']);

        $response = $this->actAsMember($this->workspace);
        foreach (['abc | Sede', '888 | A\n888 | B', '777 | Repite la principal', '888'] as $bad) {
            $this->put('/integrations/providers/booker', ['environment' => 'staging', 'api' => 'merchant', 'location_id' => '777', 'locations' => str_replace('\n', "\n", $bad)] + self::SECRETS)->assertSessionHasErrors('locations');
        }
    }

    public function test_the_connection_test_reads_the_catalog_of_the_merchant_api_with_the_token_and_changes_nothing(): void
    {
        Http::fake($this->token() + ['*/v4.1/merchant/treatments' => Http::response($this->treatments())]);

        $result = app(IntegrationService::class)->test($this->connect());

        $this->assertTrue($result->ok);
        $this->assertCount(1, $this->recorded('/v4.1/merchant/treatments'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v4.1/merchant/treatments')
            && $request->hasHeader('Authorization', 'Bearer tok-123') && $request->hasHeader('Ocp-Apim-Subscription-Key', 'SUB-s3cret-3')
            && $request['access_token'] === 'tok-123' && $request['LocationID'] === 777);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'appointment'));
        $this->assertStringStartsWith('https://api-staging.booker.com/', Http::recorded()[0][0]->url());
    }

    public function test_the_environment_decides_the_host_and_what_the_agenda_offers(): void
    {
        $staging = $this->calendar($this->connect());
        $this->assertSame(['availability' => true, 'book' => true], ['availability' => $staging->capabilities()['availability'], 'book' => $staging->capabilities()['book']]);

        $production = $this->calendar($this->connect(null, ['environment' => 'production']));
        // Not verified yet: production gets no availability or booking from Ava, and says so.
        $this->assertSame([false, false, false, false, false], [$production->capabilities()['availability'], $production->capabilities()['book'], $production->capabilities()['cancel'], $production->capabilities()['reschedule'], $production->capabilities()['professionals']]);

        config(['integrations.booker.merchant.verified' => true]);
        $this->assertTrue($this->calendar($this->connect(null, ['environment' => 'production']))->capabilities()['book']);
    }

    // --- catalog ----------------------------------------------------------------------------------------------------------------

    public function test_treatments_become_services_without_classes_and_are_cached_per_location(): void
    {
        Http::fake($this->token() + ['*/v4.1/merchant/treatments' => Http::response($this->treatments())]);
        $calendar = $this->calendar($this->connect());

        $services = $calendar->services();
        $calendar->services();
        $this->assertSame([['1', 'Manicure', 45, 30.0]], array_map(fn ($s) => [$s['id'], $s['name'], $s['duration_minutes'], $s['price']], $services));
        $this->assertCount(1, $this->recorded('/v4.1/merchant/treatments'), 'the catalog is read once, not on every call');

        $calendar->forLocation(888)->services();
        $this->assertCount(2, $this->recorded('/v4.1/merchant/treatments'), 'another location has its own catalog');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/merchant/treatments') && $request['LocationID'] === 888);
    }

    // --- availability -----------------------------------------------------------------------------------------------------------

    public function test_availability_reads_one_day_at_a_time_in_the_workspace_time_and_understands_both_date_forms(): void
    {
        $ms = CarbonImmutable::parse('2026-10-13T11:00:00Z')->getTimestampMs();
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00', "/Date({$ms}+0000)/"])),
        ]);
        $from = CarbonImmutable::parse('2026-10-13T00:00:00Z');

        $slots = $this->calendar($this->connect())->availability('1', null, $from, $from->addDay());

        $this->assertSame(['2026-10-13T10:00:00Z', '2026-10-13T11:00:00Z'], array_map(fn ($s) => $s['starts_at']->utc()->toIso8601ZuluString(), $slots));
        $this->assertSame(['11', '11'], array_column($slots, 'professional_id'));
        $this->assertSame('2026-10-13T10:45:00Z', $slots[0]['ends_at']->utc()->toIso8601ZuluString(), 'the end falls back to the treatment duration');
        $this->assertCount(1, $this->recorded('/v4.1/merchant/availability/1day'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/availability/1day') && $request['LocationID'] === 777 && $request['StartDateTime'] === '2026-10-13T00:00:00' && $request['Itineraries'][0]['Treatments'][0]['TreatmentID'] === 1);
    }

    public function test_slots_of_other_treatments_or_employees_are_not_offered_and_an_unknown_shape_offers_nothing(): void
    {
        Http::fake($this->token() + ['*/v4.1/merchant/availability/1day' => Http::response(['ItineraryTimeSlotLists' => [['Slots' => [
            ['StartDateTime' => '2026-10-13T10:00:00', 'TreatmentTimeSlots' => [['TreatmentID' => 1, 'EmployeeID' => 11]]],
            ['StartDateTime' => '2026-10-13T11:00:00', 'TreatmentTimeSlots' => [['TreatmentID' => 9, 'EmployeeID' => 11]]],
            ['StartDateTime' => '2026-10-13T12:00:00', 'TreatmentTimeSlots' => [['TreatmentID' => 1, 'EmployeeID' => 22]]],
            ['StartDateTime' => 'not a date', 'TreatmentTimeSlots' => [['TreatmentID' => 1, 'EmployeeID' => 11]]],
        ]]]])]);
        $calendar = $this->calendar($this->connect());
        $from = CarbonImmutable::parse('2026-10-13T00:00:00Z');

        $this->assertSame(['2026-10-13T10:00:00Z'], array_map(fn ($s) => $s['starts_at']->toIso8601ZuluString(), $calendar->availability('1', 11, $from, $from->addDay())));

        $this->resetHttp();
        Http::fake($this->token() + ['*/v4.1/merchant/availability/1day' => Http::response(['Something' => 'else'])]);
        $this->expectExceptionObject(ProviderException::invalid());
        $calendar->availability('1', null, $from, $from->addDay());
    }

    public function test_several_locations_force_a_choice_and_only_the_configured_ones_exist(): void
    {
        Http::fake($this->token() + ['*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])), '*/v4.1/merchant/treatments' => Http::response($this->treatments())]);
        $this->connect();
        $from = CarbonImmutable::parse('2026-10-13T00:00:00Z');
        $workspace = $this->workspace->fresh();

        try {
            $this->manager()->availability($workspace, '1', null, $from, 1);
            $this->fail('With several locations one must be named.');
        } catch (BookingException $e) {
            $this->assertSame([BookingException::INVALID, 'Indica la sede donde quieres la cita.'], [$e->reason, $e->getMessage()]);
        }
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Esa sede no está disponible.');

        try {
            $this->assertCount(1, $this->manager()->availability($workspace, '1', null, $from, 1, null, 888));
            Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/availability/1day') && $request['LocationID'] === 888);
            $this->assertSame(['locations' => [['id' => '777', 'name' => 'Sede principal'], ['id' => '888', 'name' => 'Sede Norte'], ['id' => '999', 'name' => 'Sede Sur']], 'required' => true], $this->manager()->externalLocations($workspace));
        } finally {
            $this->manager()->availability($workspace, '1', null, $from, 1, null, 1234); // not one of its locations
        }
    }

    // --- booking ----------------------------------------------------------------------------------------------------------------

    public function test_booking_rereads_availability_finds_the_customer_and_creates_with_the_slot_exactly_as_booker_sent_it(): void
    {
        $raw = '/Date('.CarbonImmutable::parse('2026-10-13T10:00:00Z')->getTimestampMs().'-0500)/';
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots([$raw])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => [['ID' => 4321, 'Email' => 'laura@example.com']]]),
            '*/v4.1/merchant/appointment/create' => Http::response(['Appointment' => ['ID' => 9001]]),
        ]);
        $this->connect();

        $result = $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat');

        $appointment = $result['appointment'];
        $this->assertSame(['9001', 'confirmed', 'synced', 'booker', 'Sede Norte'], [$appointment->external_id, $appointment->status, $appointment->sync_status, $appointment->provider, $appointment->location_name]);
        // Order of the recommended flow: availability is read before the customer is searched and before the appointment is created.
        $order = Http::recorded()->map(fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)))->reject(fn ($name) => $name === 'token')->values()->all();
        $this->assertSame(['treatments', '1day', 'customers', 'create'], array_slice($order, array_search('treatments', $order), 4));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/merchant/customers') && $request['Email'] === 'laura@example.com' && $request['LocationID'] === 888);
        Http::assertSent(function (Request $request) use ($raw) {
            if (! str_ends_with($request->url(), '/merchant/appointment/create')) {
                return false;
            }
            $slot = $request['ItineraryTimeSlotList'][0]['Slots'][0];

            return $request['LocationID'] === 888 && $request['access_token'] === 'tok-123' && $request['Customer'] === ['ID' => 4321]
                && $slot['StartDateTime'] === $raw && $slot['TreatmentTimeSlots'][0]['EmployeeID'] === 11 && count($request['ItineraryTimeSlotList'][0]['Slots']) === 1
                && $request['ItineraryTimeSlotList'][0]['Token'] === 'itinerary-opaque' && ! isset($request['PaymentInfo']);
        });
    }

    public function test_a_new_customer_is_described_only_when_booker_does_not_know_them_and_an_ambiguous_match_refuses_the_booking(): void
    {
        $fake = fn (array $customers) => Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => $customers]),
            '*/v4.1/merchant/appointment/create' => Http::response(['ID' => 77]),
        ]);
        $fake([]);
        $this->connect();

        $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/appointment/create') && $request['Customer'] === ['FirstName' => 'Laura', 'LastName' => 'Pérez', 'Email' => 'laura@example.com', 'CellPhone' => '+573001112233']);
        $this->assertCount(2, $this->recorded('/merchant/customers'), 'email first, then the phone, before creating anyone');

        // Two people share the phone in Booker: Ava does not guess which one is this contact and does not create a third.
        $this->resetHttp();
        Cache::flush();
        $fake([['ID' => 1, 'Email' => 'a@example.com'], ['ID' => 2, 'Email' => 'b@example.com']]);
        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(['starts_at' => CarbonImmutable::parse('2026-10-13T10:00:00Z'), 'customer' => ['name' => 'Otra Persona', 'phone' => '+573009998877']]), 'chat');
            $this->fail('An ambiguous customer must refuse the booking.');
        } catch (BookingException $e) {
            $this->assertSame('provider', $e->reason);
        }
        $this->assertCount(0, $this->recorded('/appointment/create'));
        $this->assertSame('failed', Appointment::orderByDesc('id')->first()->status);
    }

    public function test_a_slot_that_booker_no_longer_offers_is_a_conflict_and_nothing_is_created(): void
    {
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T15:00:00'])),
        ]);
        $this->connect();

        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat');
            $this->fail('A start Booker does not offer must be refused.');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::SLOT_UNAVAILABLE, $e->reason);
        }

        $this->assertCount(0, $this->recorded('/appointment/create'));
        $this->assertSame(['failed'], Appointment::pluck('status')->all());
    }

    public function test_a_booking_is_never_repeated_and_a_lost_answer_is_not_a_confirmation(): void
    {
        $base = fn (array $create) => Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => [['ID' => 5]]]),
            '*/v4.1/merchant/appointment/create' => $create[0],
        ]);
        $this->connect();
        $conversation = $this->workspace->chatbots()->create(['name' => 'A', 'instructions' => 'x'])->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura']);

        // The server fails after the request: the write may exist in Booker, so it is kept as "sin confirmar" and not written again.
        $base([Http::response([], 503)]);
        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat', $conversation->id);
            $this->fail('An uncertain write must not look like a success.');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::UNCERTAIN, $e->reason);
        }
        $this->assertCount(1, $this->recorded('/appointment/create'));
        $this->assertSame(['requested', 'uncertain', null], [Appointment::sole()->status, Appointment::sole()->sync_status, Appointment::sole()->external_id]);

        // Asking again (a retried workflow) is refused without a second write.
        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat', $conversation->id);
            $this->fail('The unverified booking must block a second write.');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::UNCERTAIN, $e->reason);
        }
        $this->assertCount(1, $this->recorded('/appointment/create'));

        // A timeout is uncertain too, and a 2xx without an appointment id is not a booking Ava can vouch for.
        $calls = 0;
        $answers = [
            function () use (&$calls) {
                $calls++;
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            function () use (&$calls) {
                $calls++;

                return Http::response(['Success' => true]);
            },
        ];
        foreach ($answers as $i => $answer) {
            $this->resetHttp();
            Cache::flush();
            $calls = 0;
            $base([$answer]);
            try {
                $this->manager()->request($this->workspace->fresh(), $this->payload(['starts_at' => CarbonImmutable::parse('2026-10-13T10:00:00Z'), 'customer' => ['name' => 'Otra', 'phone' => '+57300000000'.$i]]), 'chat');
                $this->fail('Not a confirmed booking.');
            } catch (BookingException $e) {
                $this->assertSame(BookingException::UNCERTAIN, $e->reason);
            }
            $this->assertSame(1, $calls, 'one write, never repeated');
        }
    }

    public function test_the_same_request_again_returns_the_booking_ava_already_has_without_calling_booker(): void
    {
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => [['ID' => 5]]]),
            '*/v4.1/merchant/appointment/create' => Http::response(['Appointment' => ['ID' => 9002]]),
        ]);
        $this->connect();
        $conversation = $this->workspace->chatbots()->create(['name' => 'A', 'instructions' => 'x'])->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura']);

        $first = $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat', $conversation->id);
        $again = $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat', $conversation->id);

        $this->assertTrue($again['replayed']);
        $this->assertSame($first['appointment']->id, $again['appointment']->id);
        $this->assertSame(1, Appointment::count());
        $this->assertCount(1, $this->recorded('/appointment/create'));
    }

    public function test_a_refusal_in_the_body_of_a_successful_response_is_a_refusal(): void
    {
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => [['ID' => 5]]]),
            '*/v4.1/merchant/appointment/create' => Http::response(['ErrorCode' => 1004, 'ErrorMessage' => 'Customer laura@example.com is blocked']),
        ]);
        $this->connect();

        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat');
            $this->fail('An ErrorCode is a refusal.');
        } catch (BookingException $e) {
            $this->assertSame('provider', $e->reason);
            $this->assertStringNotContainsString('laura@example.com', $e->getMessage());
        }
        $this->assertSame(['failed', 'failed'], [Appointment::sole()->status, Appointment::sole()->sync_status]);
    }

    public function test_what_booker_has_no_confirmed_endpoint_for_is_refused_not_faked(): void
    {
        $this->connect();
        Http::fake($this->token());
        $appointment = $this->workspace->appointments()->create([
            'customer_id' => $this->workspace->bookingCustomers()->create(['name' => 'Laura', 'phone' => '+573001112233'])->id, 'service_name' => 'Manicure', 'starts_at' => '2026-10-20 10:00:00', 'ends_at' => '2026-10-20 10:45:00',
            'blocks_until' => '2026-10-20 10:45:00', 'status' => 'confirmed', 'provider' => 'booker', 'external_id' => '9001', 'sync_status' => 'synced', 'channel' => 'admin',
        ])->fresh();

        foreach (['cancel' => fn () => $this->manager()->cancel($appointment, null, 'admin'), 'reschedule' => fn () => $this->manager()->reschedule($appointment, CarbonImmutable::parse('2026-10-21T10:00:00Z'), null, 'admin')] as $name => $call) {
            try {
                $call();
                $this->fail("{$name} must be refused.");
            } catch (BookingException $e) {
                $this->assertSame(BookingException::UNSUPPORTED, $e->reason, $name);
            }
        }

        $this->assertSame(['confirmed', 'synced'], [$appointment->fresh()->status, $appointment->fresh()->sync_status]);
        Http::assertNothingSent();
    }

    // --- isolation, tokens and secrets -------------------------------------------------------------------------------------------

    public function test_each_workspace_uses_its_own_credentials_token_locations_and_catalog(): void
    {
        $other = $this->bookingWorkspace('OTHER', self::MODULES);
        Http::fake([
            '*/v5/auth/connect/token' => fn (Request $request) => Http::response(['access_token' => str_contains($request->body(), 'OTHER-CID') ? 'tok-other' : 'tok-main', 'expires_in' => 3600]),
            '*/v4.1/merchant/treatments' => fn (Request $request) => Http::response(['Treatments' => [['ID' => $request['LocationID'], 'Name' => 'T'.$request['LocationID'], 'IsActive' => true]]]),
        ]);
        $mine = $this->calendar($this->connect());
        $theirs = $this->calendar($this->connect($other, ['location_id' => '55', 'locations' => ''], ['client_id' => 'OTHER-CID', 'client_secret' => 'OTHER-SEC', 'subscription_key' => 'OTHER-SUB']));

        $this->assertSame(['777'], array_column($mine->services(), 'id'));
        $this->assertSame(['55'], array_column($theirs->services(), 'id'));
        Http::assertSent(fn (Request $request) => ($request->data()['LocationID'] ?? null) === 55 && $request->hasHeader('Authorization', 'Bearer tok-other') && $request->hasHeader('Ocp-Apim-Subscription-Key', 'OTHER-SUB') && $request['access_token'] === 'tok-other');
        Http::assertSent(fn (Request $request) => ($request->data()['LocationID'] ?? null) === 777 && $request->hasHeader('Authorization', 'Bearer tok-main') && $request->hasHeader('Ocp-Apim-Subscription-Key', 'SUB-s3cret-3'));
        // A location of the other Workspace is not one of mine.
        $this->expectException(ProviderException::class);
        $mine->forLocation(55);
    }

    public function test_an_expired_token_is_renewed_once_for_a_merchant_call_and_the_new_one_goes_in_the_body(): void
    {
        Http::fake([
            '*/v5/auth/connect/token' => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 3600])->push(['access_token' => 'new', 'expires_in' => 3600]),
            '*/v4.1/merchant/treatments' => Http::sequence()->push([], 401)->push($this->treatments()),
        ]);

        $this->assertCount(1, $this->calendar($this->connect())->services());

        $this->assertCount(2, $this->recorded('/token'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/merchant/treatments') && $request['access_token'] === 'new' && $request->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_no_secret_token_or_customer_datum_reaches_the_logs_the_audit_or_the_failures(): void
    {
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => []]),
            '*/v4.1/merchant/appointment/create' => Http::response(['error' => 'secret tok-123 laura@example.com'], 500),
        ]);
        $this->connect();

        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat');
        } catch (BookingException $e) {
            $this->assertStringNotContainsString('tok-123', $e->getMessage().json_encode($e->reason));
        }

        $everything = json_encode($this->logs).json_encode(AuditLog::all()->toArray()).json_encode(Appointment::all()->toArray());
        foreach (['tok-123', 's3cret', 'laura@example.com', '573001112233', 'Laura'] as $needle) {
            $this->assertStringNotContainsString($needle, $everything, $needle);
        }
    }

    public function test_the_assistant_learns_the_locations_and_that_one_must_be_chosen(): void
    {
        $this->connect();
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'x']);
        $token = app(AgentAccess::class)->generate($bot);

        $response = $this->withToken($token)->getJson('/api/agent/booking/locations')->assertOk();

        $response->assertJsonPath('required', true)->assertJsonPath('locations.1.id', 888)->assertJsonPath('locations.1.name', 'Sede Norte');
        $this->assertCount(3, $response->json('locations'));
        $this->assertSame([], array_filter($response->json('locations'), fn ($location) => $location['phone'] !== null));
    }

    public function test_the_public_page_offers_the_configured_locations_and_requires_one(): void
    {
        $this->connect();
        $config = app(PublicBooking::class)->config($this->workspace->fresh(), $this->manager());

        $this->assertTrue($config['locationRequired']);
        $this->assertSame([[777, 'Sede principal'], [888, 'Sede Norte'], [999, 'Sede Sur']], array_map(fn ($l) => [$l['id'], $l['name']], $config['locations']));
        $this->assertTrue($config['canBook']);

        // Production, not yet verified: the page says it cannot book instead of offering times it cannot vouch for.
        $this->connect(null, ['environment' => 'production']);
        $this->assertFalse(app(PublicBooking::class)->config($this->workspace->fresh(), $this->manager())['canBook']);
        try {
            $this->manager()->availability($this->workspace->fresh(), '1', null, CarbonImmutable::parse('2026-10-13T00:00:00Z'), 1, null, 888);
            $this->fail('Production is not verified.');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::UNSUPPORTED, $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_an_empty_or_broken_answer_is_never_cached_offered_or_taken_for_a_booking(): void
    {
        $calendar = $this->calendar($this->connect());
        $from = CarbonImmutable::parse('2026-10-13T00:00:00Z');

        // An empty catalog is not cached: the next read asks again.
        Http::fake($this->token() + ['*/v4.1/merchant/treatments' => Http::sequence()->push(['Treatments' => []])->push($this->treatments())]);
        $this->assertSame([], $calendar->services());
        $this->assertCount(1, $calendar->services());

        // HTML or an empty body where availability should be: an error, never "no times" and never a slot.
        foreach (['<html>gateway</html>', ''] as $body) {
            $this->resetHttp();
            Http::fake($this->token() + ['*/v4.1/merchant/availability/1day' => Http::response($body, 200)]);
            try {
                $calendar->availability('1', null, $from, $from->addDay());
                $this->fail('An unreadable availability must fail.');
            } catch (ProviderException $e) {
                $this->assertSame(ProviderException::INVALID, $e->codeName);
            }
        }
    }

    public function test_business_and_http_errors_of_the_booking_call_are_told_apart_and_none_is_repeated(): void
    {
        $this->connect();
        $answers = [
            '400' => [Http::response(['Message' => 'bad laura@example.com'], 400), 'provider', 'failed'],
            '401' => [Http::response([], 401), 'provider', 'failed'],
            '409' => [Http::response([], 409), 'slot_unavailable', 'failed'],
            '429' => [Http::response([], 429), 'provider', 'failed'],
            'html 200' => [Http::response('<html>', 200), 'uncertain', 'uncertain'],
        ];
        $i = 0;

        foreach ($answers as $name => [$answer, $reason, $sync]) {
            $this->resetHttp();
            Cache::flush();
            Http::fake($this->token() + [
                '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
                '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
                '*/v4.1/merchant/customers' => Http::response(['Customers' => [['ID' => 5]]]),
                '*/v4.1/merchant/appointment/create' => $answer,
            ]);
            try {
                $this->manager()->request($this->workspace->fresh(), $this->payload(['customer' => ['name' => 'Laura', 'phone' => '+5730011122'.(10 + $i++)]]), 'chat');
                $this->fail("{$name} must not be a success.");
            } catch (BookingException $e) {
                $this->assertSame($reason, $e->reason, $name);
            }
            // A 401 renews the token once (nothing was done, so asking again is safe); every other answer is a single write.
            $this->assertLessThanOrEqual((string) $name === '401' ? 2 : 1, $this->recorded('/appointment/create')->count(), $name);
            $this->assertSame($sync, Appointment::orderByDesc('id')->first()->sync_status, $name);
        }
        $this->assertSame(0, Appointment::where('status', 'confirmed')->count());
    }

    public function test_two_contacts_racing_for_the_same_slot_get_one_booking_and_one_honest_refusal(): void
    {
        $this->connect();
        $create = Http::sequence()->push(['Appointment' => ['ID' => 9100]])->push([], 409);
        Http::fake($this->token() + [
            '*/v4.1/merchant/treatments' => Http::response($this->treatments()),
            '*/v4.1/merchant/availability/1day' => Http::response($this->slots(['2026-10-13T10:00:00'])),
            '*/v4.1/merchant/customers' => Http::response(['Customers' => [['ID' => 5]]]),
            '*/v4.1/merchant/appointment/create' => $create,
        ]);

        $first = $this->manager()->request($this->workspace->fresh(), $this->payload(), 'chat');
        try {
            $this->manager()->request($this->workspace->fresh(), $this->payload(['customer' => ['name' => 'Otro', 'phone' => '+573009998877']]), 'chat');
            $this->fail('Booker refused the second one.');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::SLOT_UNAVAILABLE, $e->reason);
        }

        $this->assertSame(['confirmed'], Appointment::where('external_id', '9100')->pluck('status')->all());
        $this->assertSame(1, Appointment::where('status', 'confirmed')->count());
    }
}
