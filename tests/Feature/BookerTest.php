<?php

namespace Tests\Feature;

use App\Booking\BookingException;
use App\Booking\BookingManager;
use App\Booking\BookingRequest;
use App\Booking\ProviderException;
use App\Integrations\BookerClient;
use App\Integrations\IntegrationRegistry;
use App\Integrations\SafeHttpTarget;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Workspace;
use App\Services\IntegrationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * The Booker connector, tested ONLY against SIMULATED HTTP responses (`Http::fake`): Ava has no Booker Sandbox credentials, so
 * nothing here proves that Booker really answers like this. The shapes come from Booker's open-source Ruby client (see BookerClient)
 * and a real Sandbox test is still pending (pendientes.md). What IS proven here is Ava's side: the requests it builds, how it
 * reduces failures to fixed codes, that credentials stay per Workspace and out of every output, and that what Booker cannot be
 * verified to do is refused instead of faked.
 */
class BookerTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private const SECRETS = ['client_id' => 'CID-s3cret-1', 'client_secret' => 'CSEC-s3cret-2', 'subscription_key' => 'SUB-s3cret-3'];

    private const MODULES = ['dashboard', 'integrations', 'settings', 'service_catalog', 'professionals', 'bookings', 'calendar_providers'];

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['93.184.216.34']));
        $this->resetHttp();
        $this->workspace = $this->bookingWorkspace('SALON', self::MODULES);
    }

    /** `Http::fake` stacks (the first matching answer wins): start from a clean client before faking something different. */
    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']); // the Inertia SSR probe of the page tests
    }

    private function connect(?Workspace $workspace = null, array $secrets = self::SECRETS, string $location = '777'): Integration
    {
        return app(IntegrationService::class)->saveProvider($workspace ?? $this->workspace, 'booker', ['environment' => 'staging', 'location_id' => $location] + $secrets);
    }

    private function token(string $token = 'tok-123'): array
    {
        return ['*/v5/auth/connect/token' => Http::response(['access_token' => $token, 'expires_in' => 3600])];
    }

    private function treatments(int $count = 2): array
    {
        return collect(range(1, $count))->map(fn (int $i) => ['ID' => $i, 'Name' => "Servicio {$i}", 'Description' => 'D', 'Category' => 'Uñas', 'TotalDuration' => 30 + $i, 'Price' => ['Amount' => 10 * $i, 'CurrencyCode' => 'USD'], 'AllowCustomersToBookOnline' => true, 'IsActive' => true, 'IsClass' => false, 'EmployeeIDs' => [11]])->all();
    }

    private function calendar(Integration $integration)
    {
        return app(IntegrationRegistry::class)->get('booker')->calendar($integration);
    }

    // --- connection test ----------------------------------------------------------------------------------------------------

    public function test_the_connection_test_gets_a_token_and_reads_the_location_with_the_documented_requests(): void
    {
        Http::fake($this->token() + ['*/v4.1/customer/location/777' => Http::response(['ID' => 777])]);
        $integration = $this->connect();

        $result = app(IntegrationService::class)->test($integration);

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v5/auth/connect/token') && $request->method() === 'POST' && $request->hasHeader('Ocp-Apim-Subscription-Key', 'SUB-s3cret-3') && str_contains($request->body(), 'grant_type=client_credentials') && str_contains($request->body(), 'scope=public') && str_contains($request->body(), 'client_id=CID-s3cret-1'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v4.1/customer/location/777') && $request->hasHeader('Authorization', 'Bearer tok-123') && $request->hasHeader('Ocp-Apim-Subscription-Key', 'SUB-s3cret-3'));
        $this->assertStringStartsWith('https://api-staging.booker.com/', Http::recorded()[0][0]->url());
        $this->assertTrue((bool) $integration->fresh()->last_test_ok);
    }

    public function test_the_production_environment_uses_the_production_host(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 't'])]);
        $integration = $this->connect();
        $integration->forceFill(['config' => ['environment' => 'production', 'location_id' => '777']])->save();

        $this->calendar($integration->fresh()); // building it does not call anything
        (new BookerClient($integration->fresh(), app(SafeHttpTarget::class)))->authenticate();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.booker.com/'));
    }

    public function test_wrong_credentials_are_reported_with_fixed_words_and_never_echo_the_provider_or_the_secrets(): void
    {
        Http::fake(['*/v5/auth/connect/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'client CID-s3cret-1 is unknown'], 400)]);

        $result = app(IntegrationService::class)->test($this->connect());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('rechazó las credenciales', $result->message);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $result->message);
        }
        $this->assertStringNotContainsString('unknown', $result->message);
    }

    public function test_an_unknown_location_a_timeout_and_a_rate_limit_are_told_apart(): void
    {
        $integration = $this->connect();

        Http::fake($this->token() + ['*/v4.1/customer/location/777' => Http::response([], 404)]);
        $this->assertStringContainsString('no reconoce esa ubicación', app(IntegrationService::class)->test($integration)->message);

        $this->resetHttp();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds'));
        $this->assertStringContainsString('no respondió a tiempo', app(IntegrationService::class)->test($integration)->message);

        Cache::flush();
        $this->resetHttp();
        Http::fake(['*' => Http::response([], 429)]);
        $this->assertStringContainsString('limitando', app(IntegrationService::class)->test($integration)->message);

        $this->resetHttp();
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertStringContainsString('no está disponible', app(IntegrationService::class)->test($integration)->message);
        $this->assertFalse((bool) $integration->fresh()->last_test_ok);
    }

    public function test_the_address_of_booker_cannot_be_an_internal_host(): void
    {
        $this->app->instance(SafeHttpTarget::class, new SafeHttpTarget(fn (string $host) => ['10.0.0.5']));
        Http::fake();
        $integration = $this->connect();

        $result = app(IntegrationService::class)->test($integration);

        $this->assertFalse($result->ok);
        Http::assertNothingSent();
    }

    // --- reading the catalog ---------------------------------------------------------------------------------------------------

    public function test_services_come_from_treatments_without_classes_inactive_or_offline_ones(): void
    {
        $items = $this->treatments(3);
        $items[] = ['ID' => 90, 'Name' => 'Clase grupal', 'IsClass' => true, 'IsActive' => true, 'AllowCustomersToBookOnline' => true];
        $items[] = ['ID' => 91, 'Name' => 'Inactivo', 'IsClass' => false, 'IsActive' => false, 'AllowCustomersToBookOnline' => true];
        $items[] = ['ID' => 92, 'Name' => 'Solo en salón', 'IsClass' => false, 'IsActive' => true, 'AllowCustomersToBookOnline' => false];
        Http::fake($this->token() + ['*/v4.1/customer/treatments' => Http::response(['Results' => $items])]);

        $services = $this->calendar($this->connect())->services();

        $this->assertSame(['1', '2', '3'], array_column($services, 'id'));
        $this->assertSame(['Servicio 1', 31, 10.0, 'Uñas'], [$services[0]['name'], $services[0]['duration_minutes'], $services[0]['price'], $services[0]['category']]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v4.1/customer/treatments') && $request['LocationID'] === 777 && $request['UsePaging'] === true && $request['PageNumber'] === 1 && $request['PageSize'] === 100);
    }

    public function test_every_page_of_a_paginated_list_is_read(): void
    {
        $page = collect(range(1, 100))->map(fn ($i) => ['ID' => $i, 'Name' => "S{$i}", 'IsActive' => true, 'IsClass' => false, 'AllowCustomersToBookOnline' => true])->all();
        Http::fake($this->token() + ['*/v4.1/customer/treatments' => Http::sequence()->push(['Results' => $page])->push(['Results' => [['ID' => 101, 'Name' => 'S101']]])]);

        $this->assertCount(101, $this->calendar($this->connect())->services());
    }

    public function test_professionals_are_the_employees_of_the_service(): void
    {
        $treatments = $this->treatments(2);
        $treatments[1]['EmployeeIDs'] = [12];
        Http::fake($this->token() + [
            '*/v4.1/customer/treatments' => Http::response(['Results' => $treatments]),
            '*/v4.1/customer/employees' => Http::response(['Results' => [['ID' => 11, 'FirstName' => 'Ana', 'LastName' => 'Gómez'], ['ID' => 12, 'FirstName' => 'Beto', 'LastName' => '']]]),
        ]);
        $calendar = $this->calendar($this->connect());

        $this->assertSame(['Ana Gómez', 'Beto'], array_column($calendar->professionals(), 'name'));
        $this->assertSame(['Ana Gómez'], array_column($calendar->professionals(1), 'name'));
        $this->assertSame(['Beto'], array_column($calendar->professionals(2), 'name'));
    }

    // --- failures, retries and tokens ------------------------------------------------------------------------------------------

    public function test_a_read_is_tried_once_more_after_a_rate_limit_but_a_write_never_is(): void
    {
        Http::fake($this->token() + [
            '*/v4.1/customer/treatments' => Http::sequence()->push([], 429)->push(['Results' => $this->treatments(1)]),
            '*/v4.1/customer/appointment/cancel' => Http::response([], 503),
        ]);
        $calendar = $this->calendar($this->connect());

        $this->assertCount(1, $calendar->services());
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/treatments')));

        $appointment = $this->bookerAppointment();
        try {
            $calendar->cancel($appointment);
            $this->fail('A 503 must fail.');
        } catch (ProviderException $exception) {
            $this->assertSame(ProviderException::UNAVAILABLE, $exception->codeName);
        }
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/appointment/cancel'))); // not repeated: it could happen twice
    }

    public function test_a_failure_stays_a_failure_after_the_single_retry(): void
    {
        Http::fake($this->token() + ['*/v4.1/customer/treatments' => Http::response([], 503)]);

        try {
            $this->calendar($this->connect())->services();
            $this->fail('It must fail.');
        } catch (ProviderException $exception) {
            $this->assertSame([ProviderException::UNAVAILABLE, true], [$exception->codeName, $exception->retryable]);
        }
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/treatments')));
    }

    public function test_an_expired_token_is_renewed_once_and_the_call_repeated(): void
    {
        Http::fake([
            '*/v5/auth/connect/token' => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 3600])->push(['access_token' => 'new', 'expires_in' => 3600]),
            '*/v4.1/customer/treatments' => Http::sequence()->push([], 401)->push(['Results' => $this->treatments(1)]),
        ]);

        $this->assertCount(1, $this->calendar($this->connect())->services());

        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/token')));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/treatments') && $request->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_a_token_is_reused_until_it_expires_and_a_persistent_401_ends_in_an_auth_failure(): void
    {
        Http::fake($this->token() + ['*/v4.1/customer/treatments' => Http::response(['Results' => $this->treatments(1)]), '*/v4.1/customer/employees' => Http::response(['Results' => []])]);
        $calendar = $this->calendar($this->connect());
        $calendar->services();
        Cache::flush();
        $calendar->services();
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/token'))); // one per cache lifetime, not one per call

        Cache::flush();
        $this->resetHttp();
        Http::fake($this->token() + ['*/v4.1/customer/employees' => Http::response([], 401)]);
        $this->expectExceptionObject(ProviderException::auth());
        $calendar->professionals();
    }

    public function test_a_timeout_is_a_timeout_and_a_conflict_is_a_conflict(): void
    {
        $calendar = $this->calendar($this->connect());
        Http::fake($this->token() + ['*/v4.1/customer/appointment/cancel' => Http::response([], 409)]);

        try {
            $calendar->cancel($this->bookerAppointment());
            $this->fail('409 must be a conflict.');
        } catch (ProviderException $exception) {
            $this->assertSame(ProviderException::CONFLICT, $exception->codeName);
        }

        Cache::flush();
        $this->resetHttp();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->expectExceptionObject(ProviderException::timeout());
        $calendar->cancel($this->bookerAppointment(['external_id' => '556']));
    }

    // --- appointments ------------------------------------------------------------------------------------------------------------

    private function bookerAppointment(array $overrides = []): Appointment
    {
        $customer = $this->workspace->bookingCustomers()->first() ?? $this->workspace->bookingCustomers()->create(['name' => 'Laura', 'phone' => '+573001112233']);

        return $this->workspace->appointments()->create($overrides + [
            'customer_id' => $customer->id, 'service_name' => 'Servicio 1', 'starts_at' => '2026-10-20 10:00:00', 'ends_at' => '2026-10-20 10:30:00', 'blocks_until' => '2026-10-20 10:30:00',
            'status' => 'confirmed', 'provider' => 'booker', 'external_id' => '555', 'sync_status' => 'synced', 'channel' => 'admin',
        ])->fresh();
    }

    public function test_an_appointment_is_read_and_cancelled_with_the_documented_calls(): void
    {
        Http::fake($this->token() + [
            '*/v4.1/customer/appointment/555' => Http::response(['ID' => 555, 'IsCancelled' => false, 'IsHeld' => false]),
            '*/v4.1/customer/appointment/cancel' => Http::response(['ID' => 555, 'IsCancelled' => true]),
        ]);
        $calendar = $this->calendar($this->connect());
        $appointment = $this->bookerAppointment();

        $this->assertSame('confirmed', $calendar->find($appointment)->status);
        $calendar->cancel($appointment);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/v4.1/customer/appointment/cancel') && $request['ID'] === 555);
    }

    public function test_a_held_appointment_is_not_reported_as_confirmed_and_a_cancelled_or_unknown_one_is_reported_as_such(): void
    {
        $calendar = $this->calendar($this->connect());
        $appointment = $this->bookerAppointment();

        Http::fake($this->token() + ['*/v4.1/customer/appointment/555' => Http::response(['ID' => 555, 'IsHeld' => true])]);
        $this->assertSame('requested', $calendar->find($appointment)->status);

        $this->resetHttp();
        Http::fake($this->token() + ['*/v4.1/customer/appointment/555' => Http::response(['Appointment' => ['ID' => 555, 'IsCancelled' => true]])]);
        $this->assertSame('cancelled', $calendar->find($appointment)->status);

        $this->resetHttp();
        Http::fake($this->token() + ['*/v4.1/customer/appointment/555' => Http::response([], 404)]);
        $this->assertNull($calendar->find($appointment));
    }

    public function test_availability_booking_and_rescheduling_are_declared_unsupported_and_call_nothing(): void
    {
        Http::fake();
        $calendar = $this->calendar($this->connect());

        $this->assertSame(['services' => true, 'professionals' => true, 'availability' => false, 'book' => false, 'reschedule' => false, 'cancel' => true, 'find' => true], $calendar->capabilities());

        foreach ([
            fn () => $calendar->availability(1, null, CarbonImmutable::now(), CarbonImmutable::now()->addDay()),
            fn () => $calendar->book(new BookingRequest(1, null, CarbonImmutable::now(), ['name' => 'X', 'phone' => '1', 'email' => null])),
            fn () => $calendar->reschedule($this->bookerAppointment(), CarbonImmutable::now(), null),
        ] as $call) {
            try {
                $call();
                $this->fail('It must be unsupported.');
            } catch (ProviderException $exception) {
                $this->assertSame(ProviderException::UNSUPPORTED, $exception->codeName);
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_manager_tells_the_customer_that_booker_cannot_do_it_yet_and_records_nothing(): void
    {
        Http::fake();
        $this->connect();
        $this->workspace->bookingSetting()->updateOrCreate([], ['provider' => 'booker']);

        $capabilities = app(BookingManager::class)->capabilities($this->workspace);
        $this->assertSame(['booker', true, false, false, true], [$capabilities['provider'], $capabilities['connected'], $capabilities['availability'], $capabilities['book'], $capabilities['external']]);

        foreach ([
            fn () => app(BookingManager::class)->availability($this->workspace, '1', null, CarbonImmutable::now(), 3),
            fn () => app(BookingManager::class)->request($this->workspace, ['service_id' => '1', 'starts_at' => CarbonImmutable::now(), 'customer' => ['name' => 'X', 'phone' => '1', 'email' => null]], 'web'),
        ] as $call) {
            try {
                $call();
                $this->fail('It must be refused.');
            } catch (BookingException $exception) {
                $this->assertSame(BookingException::UNSUPPORTED, $exception->reason);
                $this->assertStringContainsString('Booker', $exception->getMessage());
            }
        }

        $this->assertSame(0, Appointment::count());
        Http::assertNothingSent();
    }

    // --- credentials ----------------------------------------------------------------------------------------------------------------

    public function test_credentials_are_encrypted_at_rest_and_never_reach_the_page_the_audit_or_the_form(): void
    {
        $integration = $this->connect();
        $raw = (string) DB::table('integrations')->where('id', $integration->id)->value('secrets');
        $this->actingAs($this->member($this->workspace, 'admin'))->withSession(['workspace_id' => $this->workspace->id]);

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
            $this->assertStringNotContainsString($secret, json_encode($integration->fresh()->toArray()));
            $this->assertStringNotContainsString($secret, json_encode(AuditLog::all()->toArray()));
        }

        $page = $this->get('/integrations')->assertOk()->viewData('page');
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($page));
        }
        $this->assertTrue($page['props']['providers'][0]['integration']['form']['client_secret_set']);
        $this->assertSame('', $page['props']['providers'][0]['integration']['form']['client_secret']);
    }

    public function test_editing_without_retyping_the_secrets_keeps_them_and_a_new_one_replaces_it(): void
    {
        $integration = $this->connect();
        $this->actingAs($this->member($this->workspace, 'admin'))->withSession(['workspace_id' => $this->workspace->id]);

        $this->put('/integrations/providers/booker', ['environment' => 'production', 'location_id' => '888', 'client_id' => '', 'client_secret' => '', 'subscription_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::SECRETS, array_intersect_key($integration->fresh()->secrets, self::SECRETS));
        $this->assertSame(['production', '888'], [$integration->fresh()->config['environment'], $integration->fresh()->config['location_id']]);
        $this->assertNull($integration->fresh()->last_tested_at); // what was verified before no longer describes the new data

        $this->put('/integrations/providers/booker', ['environment' => 'production', 'location_id' => '888', 'client_secret' => 'NEW-secret'])->assertSessionHasNoErrors();
        $this->assertSame('NEW-secret', $integration->fresh()->secrets['client_secret']);
    }

    public function test_each_workspace_uses_its_own_credentials_and_token(): void
    {
        $other = $this->bookingWorkspace('OTRO', self::MODULES);
        $mine = $this->connect();
        $theirs = $this->connect($other, ['client_id' => 'OTHER-CID', 'client_secret' => 'OTHER-SEC', 'subscription_key' => 'OTHER-SUB'], '999');
        Http::fake(['*/v5/auth/connect/token' => Http::sequence()->push(['access_token' => 'tok-A', 'expires_in' => 3600])->push(['access_token' => 'tok-B', 'expires_in' => 3600]), '*/v4.1/customer/treatments' => Http::response(['Results' => []])]);

        $this->calendar($mine)->services();
        $this->calendar($theirs)->services();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/treatments') && $request->hasHeader('Authorization', 'Bearer tok-A') && $request['LocationID'] === 777 && $request->hasHeader('Ocp-Apim-Subscription-Key', 'SUB-s3cret-3'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/treatments') && $request->hasHeader('Authorization', 'Bearer tok-B') && $request['LocationID'] === 999 && $request->hasHeader('Ocp-Apim-Subscription-Key', 'OTHER-SUB'));
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/token')));
    }

    // --- plan, role and pages ------------------------------------------------------------------------------------------------------

    public function test_the_connection_is_only_offered_and_accepted_with_the_module_in_the_plan_and_the_permission(): void
    {
        $this->contractModules($this->workspace, array_diff(self::MODULES, ['calendar_providers']));
        $integration = $this->connect();
        $this->actingAs($this->member($this->workspace, 'admin'))->withSession(['workspace_id' => $this->workspace->id]);

        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->has('providers', 0));
        $this->put('/integrations/providers/booker', ['environment' => 'staging', 'location_id' => '1'])->assertForbidden();
        $this->post("/integrations/{$integration->id}/test")->assertForbidden();
        $this->post("/integrations/{$integration->id}/deactivate")->assertForbidden();

        $this->contractModules($this->workspace, self::MODULES);
        $this->get('/integrations')->assertInertia(fn (AssertableInertia $page) => $page->has('providers', 1)->where('providers.0.key', 'booker')->where('providers.0.testable', true));

        $this->actingAs($this->member($this->workspace, 'supervisor'))->withSession(['workspace_id' => $this->workspace->id]); // no manage-settings
        $this->put('/integrations/providers/booker', ['environment' => 'staging', 'location_id' => '1'])->assertForbidden();
    }

    public function test_the_provider_form_validates_and_an_unknown_provider_does_not_exist(): void
    {
        $this->actingAs($this->member($this->workspace, 'admin'))->withSession(['workspace_id' => $this->workspace->id]);

        $this->put('/integrations/providers/booker', ['environment' => 'nowhere', 'location_id' => 'abc'])->assertSessionHasErrors(['environment', 'location_id']);
        $this->put('/integrations/providers/booker', ['environment' => 'staging', 'location_id' => '1'])->assertSessionHasErrors('client_id'); // a first save needs every credential
        $this->put('/integrations/providers/http', ['environment' => 'staging'])->assertNotFound();
        $this->put('/integrations/providers/nope', [])->assertNotFound();
        $this->assertSame(0, Integration::count());
    }

    public function test_booker_chosen_as_the_agenda_shows_its_catalog_read_only_and_refuses_local_edits_and_bookings(): void
    {
        Http::fake($this->token() + ['*/v4.1/customer/treatments' => Http::response(['Results' => $this->treatments(2)]), '*/v4.1/customer/employees' => Http::response(['Results' => [['ID' => 11, 'FirstName' => 'Ana', 'LastName' => '']]])]);
        $this->connect();
        $this->actingAs($this->member($this->workspace, 'admin'))->withSession(['workspace_id' => $this->workspace->id]);
        $own = $this->makeService($this->workspace, ['name' => 'De Ava']);

        $this->put('/bookings/settings', ['provider' => 'booker', 'requires_confirmation' => false, 'min_notice_minutes' => 60, 'max_advance_days' => 30, 'cancel_notice_hours' => 2, 'slot_step_minutes' => 15, 'require_phone' => true, 'require_email' => false, 'public_enabled' => false])->assertSessionHasNoErrors();

        $this->get('/services')->assertInertia(fn (AssertableInertia $page) => $page->where('capabilities.external', true)->has('external', 2)->where('external.0.name', 'Servicio 1')->has('services', 0));
        $this->get('/professionals')->assertInertia(fn (AssertableInertia $page) => $page->has('external', 1)->has('professionals', 0));
        $this->post('/services', ['name' => 'Nuevo', 'duration_minutes' => 30])->assertSessionHas('error');
        $this->put("/services/{$own->id}", ['name' => 'Cambiado', 'duration_minutes' => 30])->assertSessionHas('error');
        $this->post('/professionals', ['name' => 'Nueva'])->assertSessionHas('error');
        $this->post('/bookings', ['service_id' => '1', 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertSessionHas('error');

        $this->assertSame('De Ava', $own->fresh()->name);
        $this->assertSame(0, Appointment::count());
        $this->assertSame(1, $this->workspace->services()->count());
    }

    public function test_a_failure_of_booker_shows_a_fixed_message_on_the_page_not_a_crash(): void
    {
        Http::fake($this->token() + ['*/v4.1/customer/treatments' => Http::response(['error' => 'secret internal detail'], 500)]);
        $this->connect();
        $this->actingAs($this->member($this->workspace, 'admin'))->withSession(['workspace_id' => $this->workspace->id]);
        $this->workspace->bookingSetting()->updateOrCreate([], ['provider' => 'booker']);

        $this->get('/services')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('externalError', 'La agenda externa no está disponible en este momento.')->where('external', null));
    }
}
