<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BookingCustomer;
use App\Models\Customer;
use App\Models\Workspace;
use App\Services\AgentAccess;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * International phones: NEW input must carry its country ("+573001112233"), HISTORICAL numbers are never rewritten, and a number is only
 * linked to another when both say the same full international digits inside the same Workspace.
 */
class InternationalPhoneTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->freezeAgendaTime();
        $modules = ['dashboard', 'assistants', 'conversations', 'service_catalog', 'professionals', 'bookings', 'customers'];
        $this->workspace = $this->bookingWorkspace('CLINICA', $modules);
        $this->other = $this->bookingWorkspace('OTRA', $modules);
    }

    private function legacyCustomer(Workspace $workspace, string $phone, string $name = 'Antiguo'): Customer
    {
        // Written the way historical rows were: straight into the table, without the international derivation of the form.
        $id = \DB::table('customers')->insertGetId([
            'workspace_id' => $workspace->id, 'first_name' => $name, 'phone' => $phone, 'phone_normalized' => BookingCustomer::normalizePhone($phone),
            'source' => 'manual', 'marketing_consent' => 'unknown', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Customer::findOrFail($id);
    }

    private function bookWith(Workspace $workspace, string $phone, string $start = '2026-10-13 10:00:00'): void
    {
        $service = $workspace->services()->first() ?? $this->makeService($workspace);
        $professional = $workspace->professionals()->first() ?? $this->makeProfessional($workspace, [$service]);
        $this->appointments()->book($workspace, $service, $professional->id, CarbonImmutable::parse($start, 'UTC'), ['name' => 'Laura', 'phone' => $phone, 'email' => null], 'admin');
    }

    // --- the shape of an international number -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function valid(): array
    {
        return ['colombia' => ['+573001112233'], 'with separators' => ['+57 (300) 111-2233'], 'dots' => ['+57.300.111.2233'], 'usa' => ['+1 415 555 2671'], 'uk' => ['+44 20 7946 0958'], 'spain' => ['+34 612 345 678'], 'mexico' => ['+52 55 1234 5678'], 'brazil' => ['+55 11 91234 5678']];
    }

    #[DataProvider('valid')]
    public function test_a_well_formed_international_number_is_accepted_and_keeps_its_digits(string $number): void
    {
        $this->assertNull(PhoneNumber::error($number));
        $this->assertMatchesRegularExpression('/^\+\d{7,15}$/', PhoneNumber::e164($number));
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return [
            'no plus, local' => ['3001112233'], 'no plus, with country' => ['573001112233'], 'double zero is not new input' => ['0057 300 111 2233'], 'zero country code' => ['+0573001112233'],
            'unknown country code' => ['+999 123 456 789'], 'too short' => ['+57 123'], 'too long' => ['+57 3001112233445566'], 'letters' => ['+57 300 ABC 2233'],
            'second plus' => ['+57+3001112233'], 'north america needs ten digits' => ['+1 415 555 267'], 'only the plus' => ['+'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_a_number_without_its_own_country_or_malformed_is_refused_with_an_example(string $number): void
    {
        $this->assertNull(PhoneNumber::e164($number));
        $this->assertStringContainsString('+57', PhoneNumber::error($number));
    }

    public function test_the_double_zero_call_prefix_is_only_read_by_the_controlled_review(): void
    {
        $this->assertNull(PhoneNumber::e164('0057 300 111 2233'));
        $this->assertSame('+573001112233', PhoneNumber::e164('0057 300 111 2233', true));
        $this->assertNull(PhoneNumber::e164('300 111 2233', true), 'a local number has no evidence of its country');
    }

    // --- entry points -------------------------------------------------------------------------------------------------

    public function test_customers_need_an_international_phone_and_keep_what_was_typed(): void
    {
        $this->actAsMember($this->workspace);
        $payload = ['first_name' => 'Laura', 'source' => 'manual', 'marketing_consent' => 'unknown'];

        $this->post('/customers', $payload + ['phone' => '3001112233'])->assertSessionHasErrors('phone');
        $this->post('/customers', $payload + ['phone' => '573001112233'])->assertSessionHasErrors('phone');
        $this->assertSame(0, Customer::count());

        $this->post('/customers', $payload + ['phone' => '+57 300 111 2233'])->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertSame('+57 300 111 2233', $customer->phone, 'the original text is kept');
        $this->assertSame('573001112233', $customer->phone_normalized);
        $this->assertSame('+573001112233', $customer->phone_e164);
    }

    public function test_the_error_message_tells_the_person_how_to_write_the_number(): void
    {
        $this->actAsMember($this->workspace);

        $this->post('/customers', ['first_name' => 'Laura', 'phone' => '300 111 2233', 'source' => 'manual', 'marketing_consent' => 'unknown'])
            ->assertSessionHasErrors(['phone' => 'Escribe el teléfono en formato internacional: empieza por + y el prefijo del país, por ejemplo +573001112233.']);
    }

    public function test_editing_an_old_customer_does_not_force_a_rewrite_of_its_historical_phone_but_changing_it_does(): void
    {
        $old = $this->legacyCustomer($this->workspace, '300 111 2233');
        $this->actAsMember($this->workspace);
        $payload = ['first_name' => 'Antiguo', 'last_name' => 'Editado', 'source' => 'manual', 'marketing_consent' => 'unknown'];

        $this->put("/customers/{$old->id}", $payload + ['phone' => '300 111 2233'])->assertSessionHasNoErrors();
        $fresh = $old->fresh();
        $this->assertSame('Editado', $fresh->last_name);
        $this->assertSame('300 111 2233', $fresh->phone);
        $this->assertSame('3001112233', $fresh->phone_normalized);
        $this->assertNull($fresh->phone_e164, 'saving a historical row for another reason does not give it an international identity');

        $this->put("/customers/{$old->id}", $payload + ['phone' => '300 111 9999'])->assertSessionHasErrors('phone');
        $this->assertSame('300 111 2233', $old->fresh()->phone);

        $this->put("/customers/{$old->id}", $payload + ['phone' => '+57 300 111 2233'])->assertSessionHasNoErrors();
        $this->assertSame('+573001112233', $old->fresh()->phone_e164);
    }

    public function test_every_way_of_booking_needs_an_international_phone(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
        $key = $this->workspace->bookingSetting()->updateOrCreate([], ['public_enabled' => true])->ensurePublicKey();
        $slot = fn (string $start) => ['service_id' => $service->id, 'starts_at' => $start];

        // The public page.
        $this->postJson("/api/booking/{$key}/appointments", $slot('2026-10-13T10:00:00Z') + ['customer' => ['name' => 'Laura', 'phone' => '3001112233']])->assertStatus(422)->assertJsonValidationErrors('customer.phone');
        $this->postJson("/api/booking/{$key}/appointments", $slot('2026-10-13T10:00:00Z') + ['customer' => ['name' => 'Laura', 'phone' => '+573001112233']])->assertCreated();

        // The agenda of the staff.
        $this->actAsMember($this->workspace);
        $this->post('/bookings', $slot('2026-10-13T12:00:00Z') + ['customer' => ['name' => 'Beto', 'phone' => '3009998888']])->assertSessionHasErrors('customer.phone');
        $this->post('/bookings', $slot('2026-10-13T12:00:00Z') + ['customer' => ['name' => 'Beto', 'phone' => '+573009998888']])->assertSessionHasNoErrors();

        $this->assertSame(['573001112233', '573009998888'], BookingCustomer::orderBy('id')->pluck('phone')->all());
    }

    public function test_the_professional_phone_follows_the_same_rule_but_an_unchanged_old_one_is_kept(): void
    {
        $this->actAsMember($this->workspace);
        $this->post('/professionals', ['name' => 'Ana', 'phone' => '3001112233'])->assertSessionHasErrors('phone');
        $professional = $this->workspace->professionals()->create(['name' => 'Beto', 'phone' => '300 000 1111']);

        $this->put("/professionals/{$professional->id}", ['name' => 'Beto Editado', 'phone' => '300 000 1111'])->assertSessionHasNoErrors();
        $this->assertSame(['Beto Editado', '300 000 1111'], [$professional->fresh()->name, $professional->fresh()->phone]);
        $this->put("/professionals/{$professional->id}", ['name' => 'Beto', 'phone' => '300 000 2222'])->assertSessionHasErrors('phone');
        $this->put("/professionals/{$professional->id}", ['name' => 'Beto', 'phone' => '+57 300 000 2222'])->assertSessionHasNoErrors();
    }

    public function test_the_automation_may_use_the_whatsapp_id_of_its_own_conversation_and_nothing_else_without_the_plus(): void
    {
        $service = $this->makeService($this->workspace);
        $this->makeProfessional($this->workspace, [$service]);
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $token = app(AgentAccess::class)->generate($bot);
        $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233', 'contact_name' => 'Laura']);
        $body = fn (string $phone) => ['channel' => 'whatsapp', 'contact_id' => '573001112233', 'service_id' => $service->id, 'starts_at' => '2026-10-13T10:00:00Z', 'customer' => ['name' => 'Laura', 'phone' => $phone]];

        // Meta's id is international by construction; any other number must say its country.
        $this->withToken($token)->postJson('/api/agent/booking/appointments', $body('3001112233'))->assertStatus(422);
        $this->withToken($token)->postJson('/api/agent/booking/appointments', $body('573009998877'))->assertStatus(422);
        $this->withToken($token)->postJson('/api/agent/booking/appointments', $body('573001112233'))->assertCreated();
        $this->assertSame('573001112233', BookingCustomer::firstOrFail()->phone);
    }

    // --- historical data is neither rewritten nor linked by guesswork -------------------------------------------------

    public function test_a_local_historical_number_is_not_linked_to_an_international_one_and_nobody_is_merged(): void
    {
        $this->actAsMember($this->workspace);
        $old = $this->legacyCustomer($this->workspace, '3001112233');
        $this->post('/customers', ['first_name' => 'Nuevo', 'phone' => '+57 300 111 2233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasNoErrors();
        $new = Customer::where('first_name', 'Nuevo')->firstOrFail();

        $this->assertSame(2, Customer::count(), 'two records: Ava does not decide that a local number is the same person');
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(['3001112233', null], [$old->fresh()->phone_normalized, $old->fresh()->phone_e164]);

        $this->bookWith($this->workspace, '3001112233');
        $this->bookWith($this->workspace, '+573001112233', '2026-10-13 12:00:00');
        $this->assertSame(2, BookingCustomer::count(), 'a local booking contact and an international one stay different people');

        $this->get("/customers/{$old->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 1));
        $this->get("/customers/{$new->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 1));
    }

    public function test_an_international_customer_sees_the_bookings_of_the_same_full_number_in_every_written_form(): void
    {
        $this->actAsMember($this->workspace);
        $customer = $this->workspace->customers()->create(['first_name' => 'Laura', 'phone' => '+57 (300) 111-2233', 'source' => 'manual', 'marketing_consent' => 'unknown']);
        $this->bookWith($this->workspace, '+573001112233');
        $this->bookWith($this->workspace, '0057 300 111 2233', '2026-10-13 12:00:00'); // historical: the 00 call prefix is explicit
        $this->bookWith($this->workspace, '3001112233', '2026-10-13 14:00:00'); // historical local number: country unknown, never linked

        $this->get("/customers/{$customer->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 2));
    }

    public function test_historical_customers_and_bookings_with_the_same_digits_keep_the_link_they_always_had(): void
    {
        $this->actAsMember($this->workspace);
        $old = $this->legacyCustomer($this->workspace, '573001112233');
        $this->bookWith($this->workspace, '573001112233');

        $this->get("/customers/{$old->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 1));
    }

    public function test_the_same_number_in_another_workspace_never_links_or_collides(): void
    {
        $service = $this->makeService($this->other);
        $this->makeProfessional($this->other, [$service]);
        $this->bookWith($this->other, '+573001112233');
        $otherCustomer = $this->other->customers()->create(['first_name' => 'Ajena', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        $this->actAsMember($this->workspace);
        $mine = $this->workspace->customers()->create(['first_name' => 'Mía', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        $this->get("/customers/{$mine->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('appointments', 0));
        $this->get("/customers/{$otherCustomer->id}")->assertNotFound();
        $this->assertSame(1, $this->other->bookingCustomers()->count());
        $this->assertSame(0, $this->workspace->bookingCustomers()->count());
    }

    public function test_a_duplicate_is_detected_by_the_international_form_even_if_the_digits_were_historical(): void
    {
        $this->actAsMember($this->workspace);
        $this->legacyCustomer($this->workspace, '0057 300 111 2233');
        \DB::table('customers')->where('workspace_id', $this->workspace->id)->update(['phone_e164' => '+573001112233']);

        $this->post('/customers', ['first_name' => 'Otro', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasErrors('phone');
        $this->assertSame(1, Customer::count());
    }

    // --- the controlled review of historical numbers ------------------------------------------------------------------

    private function review(array $options = []): string
    {
        Artisan::call('customers:review-phones', $options);

        return Artisan::output();
    }

    public function test_the_review_is_a_dry_run_by_default_and_changes_nothing(): void
    {
        $this->legacyCustomer($this->workspace, '+57 300 111 2233', 'Con más');
        $this->legacyCustomer($this->workspace, '0057 300 555 6677', 'Con doble cero');
        $this->legacyCustomer($this->workspace, '300 999 8888', 'Local');
        $before = \DB::table('customers')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $output = $this->review(['--workspace' => $this->workspace->id]);

        $this->assertStringContainsString('SIMULACIÓN', $output);
        $this->assertStringContainsString('actualizables 2', $output);
        $this->assertStringContainsString('sin prefijo comprobable (se dejan igual) 1', $output);
        $this->assertSame($before, \DB::table('customers')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_applying_the_review_needs_one_workspace_and_only_fills_the_separate_column(): void
    {
        $with = $this->legacyCustomer($this->workspace, '+57 300 111 2233', 'Con más');
        $zero = $this->legacyCustomer($this->workspace, '0057 300 555 6677', 'Con doble cero');
        $local = $this->legacyCustomer($this->workspace, '300 999 8888', 'Local');
        $foreign = $this->legacyCustomer($this->other, '+57 300 777 0000', 'Ajeno');

        $this->assertSame(1, Artisan::call('customers:review-phones', ['--apply' => true]), 'no Workspace, no write');
        $this->assertNull($with->fresh()->phone_e164);

        $output = $this->review(['--apply' => true, '--workspace' => $this->workspace->id]);

        $this->assertStringContainsString('actualizados 2', $output);
        $this->assertDoesNotMatchRegularExpression('/\d{6,}/', $output, 'no phone number in the output');
        $this->assertSame(['+573001112233', '+573005556677', null], [$with->fresh()->phone_e164, $zero->fresh()->phone_e164, $local->fresh()->phone_e164]);
        $this->assertNull($foreign->fresh()->phone_e164, 'another Workspace is not touched');
        // What was typed and the historical identity are exactly as they were.
        $this->assertSame(['+57 300 111 2233', '573001112233'], [$with->fresh()->phone, $with->fresh()->phone_normalized]);
        $this->assertSame(['0057 300 555 6677', '00573005556677'], [$zero->fresh()->phone, $zero->fresh()->phone_normalized]);
        $this->assertSame(['300 999 8888', '3009998888'], [$local->fresh()->phone, $local->fresh()->phone_normalized]);
        $this->assertSame(3, Customer::whereIn('workspace_id', [$this->workspace->id])->count(), 'nobody was merged or removed');
        $this->assertStringNotContainsString('300', json_encode(AuditLog::where('resource_type', 'customer')->get()->map->changes));
    }

    public function test_the_review_skips_a_number_that_already_belongs_to_another_customer_and_can_be_reverted(): void
    {
        $a = $this->legacyCustomer($this->workspace, '+57 300 111 2233', 'A');
        $b = $this->legacyCustomer($this->workspace, '0057 300 111 2233', 'B'); // the same person written with the 00 prefix
        $output = $this->review(['--apply' => true, '--workspace' => $this->workspace->id]);

        $this->assertStringContainsString('en conflicto con otro cliente (se omiten) 2', $output, 'neither is picked: Ava does not choose which record keeps the number');
        $this->assertNull($a->fresh()->phone_e164);
        $this->assertNull($b->fresh()->phone_e164);
        $this->assertSame(2, Customer::count());

        \DB::table('customers')->where('id', $a->id)->update(['phone' => '+57 300 222 3344', 'phone_normalized' => '573002223344']);
        $this->review(['--apply' => true, '--workspace' => $this->workspace->id]);
        $this->assertSame('+573002223344', $a->fresh()->phone_e164);

        $this->review(['--revert' => true, '--workspace' => $this->workspace->id]);
        $this->assertNull($a->fresh()->phone_e164);
        $this->assertSame('+57 300 222 3344', $a->fresh()->phone);
    }

    // --- external channels keep working with historical ids -----------------------------------------------------------

    public function test_a_conversation_contact_id_is_not_validated_as_a_new_phone(): void
    {
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $integration = $this->workspace->integrations()->create(['name' => 'WhatsApp', 'type' => 'whatsapp', 'is_active' => true, 'config' => ['phone_number_id' => '100'], 'secrets' => ['access_token' => 'EAAB-SECRET-TOKEN']]);
        $bot->channels()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'integration_id' => $integration->id, 'is_active' => true]);
        $token = app(AgentAccess::class)->generate($bot);

        // The agent API reports messages from ids of any channel, historical or not: this is not "new phone input".
        $this->withToken($token)->postJson('/api/agent/messages', ['channel' => 'whatsapp', 'contact_id' => '3001112233', 'contact_name' => 'Antiguo', 'direction' => 'in', 'type' => 'text', 'body' => 'Hola'])->assertCreated();
        $this->assertSame(1, $bot->conversations()->where('contact_id', '3001112233')->count());
    }
}
