<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** Clientes: the customer record per Workspace, deduplication by normalized identifiers, consent, permissions, plan and isolation. */
class CustomersTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'customers']);
        $this->other = $this->bookingWorkspace('OTRA', ['dashboard', 'customers']);
        $this->actAsMember($this->workspace);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+57 300 111 2233', 'email' => 'Laura@Example.com', 'source' => 'manual', 'marketing_consent' => 'unknown'], $overrides);
    }

    private function customer(Workspace $workspace, array $attributes = []): Customer
    {
        return $workspace->customers()->create($attributes + ['first_name' => 'Ana', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->fresh();
    }

    public function test_a_customer_is_created_with_normalized_identifiers_tags_and_identities(): void
    {
        $this->post('/customers', $this->payload(['tags' => ['VIP', ' vip ', 'mayorista'], 'identities' => [['channel' => 'whatsapp', 'external_id' => '+57 300 111 2233']]]))->assertSessionHasNoErrors();

        $customer = $this->workspace->customers()->with(['tags', 'identities'])->firstOrFail();
        $this->assertSame(['573001112233', 'laura@example.com'], [$customer->phone_normalized, $customer->email_normalized]);
        $this->assertEqualsCanonicalizing(['vip', 'mayorista'], $customer->tags->pluck('tag')->all());
        $this->assertSame('573001112233', $customer->identities->first()->external_id);
        $this->assertSame($this->workspace->id, $customer->workspace_id);
    }

    public function test_only_the_name_and_one_way_to_reach_the_customer_are_required(): void
    {
        $this->post('/customers', ['first_name' => 'Solo nombre', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasErrors('phone');
        $this->post('/customers', $this->payload(['first_name' => '']))->assertSessionHasErrors('first_name');
        $this->post('/customers', $this->payload(['phone' => 'abc']))->assertSessionHasErrors('phone');
        $this->post('/customers', $this->payload(['phone' => '123']))->assertSessionHasErrors('phone');
        $this->post('/customers', $this->payload(['email' => 'no-es-correo', 'phone' => null]))->assertSessionHasErrors('email');
        $this->post('/customers', $this->payload(['source' => 'inventado']))->assertSessionHasErrors('source');
        $this->post('/customers', $this->payload(['preferred_channel' => 'telegram']))->assertSessionHasErrors('preferred_channel');
        $this->assertSame(0, Customer::count());

        $this->post('/customers', ['first_name' => 'Por correo', 'email' => 'a@b.co', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasNoErrors();
        $this->post('/customers', ['first_name' => 'Por canal', 'identities' => [['channel' => 'web', 'external_id' => 'visitor-1']], 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasNoErrors();
        $this->assertSame(2, Customer::count());
    }

    public function test_granting_consent_requires_the_evidence_and_records_when_it_changed(): void
    {
        $this->post('/customers', $this->payload(['marketing_consent' => 'granted']))->assertSessionHasErrors('consent_note');

        $this->post('/customers', $this->payload(['marketing_consent' => 'granted', 'consent_note' => 'Formulario web, 3 de octubre']))->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertNotNull($customer->consent_at);
        $this->assertSame('Formulario web, 3 de octubre', $customer->consent_note);

        $this->travel(2)->days();
        $this->put("/customers/{$customer->id}", $this->payload(['marketing_consent' => 'revoked', 'consent_note' => 'Escribió STOP']))->assertSessionHasNoErrors();
        $fresh = $customer->fresh();
        $this->assertSame('revoked', $fresh->marketing_consent);
        $this->assertTrue($fresh->consent_at->gt($customer->consent_at));
    }

    public function test_a_duplicate_phone_email_or_channel_id_is_refused_and_never_merged(): void
    {
        $existing = $this->customer($this->workspace, ['first_name' => 'Ana', 'phone' => '+57 300 111 2233', 'email' => 'ana@example.com']);
        $existing->identities()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);

        // The same number written differently, the same email in other case, the same channel id.
        $this->post('/customers', $this->payload(['phone' => '+57 (300) 111-2233', 'email' => 'otra@example.com']))->assertSessionHasErrors('phone');
        $this->post('/customers', $this->payload(['phone' => '+573999999999', 'email' => 'ANA@EXAMPLE.COM']))->assertSessionHasErrors('email');
        $this->post('/customers', $this->payload(['phone' => '+573999999999', 'email' => 'x@example.com', 'identities' => [['channel' => 'whatsapp', 'external_id' => '+573001112233']]]))->assertSessionHasErrors('identities.0.external_id');

        $this->assertSame(1, Customer::count());
        $this->assertSame('Ana', $existing->fresh()->first_name);
    }

    public function test_data_that_points_to_two_different_customers_is_ambiguous_and_refused(): void
    {
        $this->customer($this->workspace, ['first_name' => 'Ana', 'phone' => '+573001112233']);
        $this->customer($this->workspace, ['first_name' => 'Beto', 'phone' => '+573005556677', 'email' => 'beto@example.com']);

        $response = $this->post('/customers', $this->payload(['phone' => '+573001112233', 'email' => 'beto@example.com']));

        $response->assertSessionHasErrors(['phone', 'email']);
        $this->assertStringContainsString('Ana', session('errors')->first('phone'));
        $this->assertStringContainsString('Beto', session('errors')->first('phone'));
        $this->assertSame(2, Customer::count());
    }

    public function test_editing_a_customer_does_not_collide_with_itself_but_does_with_others(): void
    {
        $ana = $this->customer($this->workspace, ['first_name' => 'Ana', 'phone' => '+573001112233']);
        $beto = $this->customer($this->workspace, ['first_name' => 'Beto', 'phone' => '+573005556677']);

        $this->put("/customers/{$ana->id}", $this->payload(['first_name' => 'Ana María', 'phone' => '+57 300 111 2233', 'email' => null]))->assertSessionHasNoErrors();
        $this->assertSame('Ana María', $ana->fresh()->first_name);

        $this->put("/customers/{$ana->id}", $this->payload(['phone' => '+57 300 555 6677']))->assertSessionHasErrors('phone');
        $this->assertSame('573001112233', $ana->fresh()->phone_normalized);
        $this->assertSame('573005556677', $beto->fresh()->phone_normalized);
    }

    public function test_the_same_phone_in_another_workspace_is_a_different_customer(): void
    {
        $this->customer($this->other, ['first_name' => 'Ana', 'phone' => '+573001112233']);

        $this->post('/customers', $this->payload(['phone' => '+573001112233']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->workspace->customers()->count());
        $this->assertSame(1, $this->other->customers()->count());
    }

    public function test_customers_of_another_workspace_are_never_reachable_listed_or_changed(): void
    {
        $foreign = $this->customer($this->other, ['first_name' => 'Ajena', 'phone' => '+573009998888']);
        $mine = $this->customer($this->workspace, ['first_name' => 'Mía', 'phone' => '+573001110000']);

        $this->get("/customers/{$foreign->id}")->assertNotFound();
        $this->put("/customers/{$foreign->id}", $this->payload(['phone' => '+573009998888']))->assertNotFound();
        $this->post("/customers/{$foreign->id}/deactivate")->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);

        $this->get('/customers')->assertInertia(fn (AssertableInertia $page) => $page->where('customers.meta.total', 1)->where('customers.data.0.id', $mine->id));
        // A workspace_id sent by the client is ignored.
        $this->post('/customers', $this->payload(['phone' => '+573112223344', 'email' => 'z@z.co', 'workspace_id' => $this->other->id]));
        $this->assertSame(0, $this->other->customers()->where('phone_normalized', '573112223344')->count());
    }

    public function test_search_and_filters_work_inside_the_workspace(): void
    {
        $ana = $this->customer($this->workspace, ['first_name' => 'Ana', 'last_name' => 'Gómez', 'phone' => '+573001112233', 'email' => 'ana@x.co', 'source' => 'booking', 'marketing_consent' => 'granted', 'consent_note' => 'ok']);
        $ana->tags()->create(['workspace_id' => $this->workspace->id, 'tag' => 'vip']);
        $ana->identities()->create(['workspace_id' => $this->workspace->id, 'channel' => 'web', 'external_id' => 'visitor-9']);
        $this->customer($this->workspace, ['first_name' => 'Beto', 'phone' => '+573005556677']);

        $ids = fn (string $query) => collect($this->get('/customers?'.$query)->viewData('page')['props']['customers']['data'])->pluck('id')->all();

        $this->assertSame([$ana->id], $ids('search=g%C3%B3mez'));
        $this->assertSame([$ana->id], $ids('search=111'));
        $this->assertSame([$ana->id], $ids('search=ANA@X.CO'));
        $this->assertSame([$ana->id], $ids('search=visitor-9'));
        $this->assertSame([$ana->id], $ids('source=booking'));
        $this->assertSame([$ana->id], $ids('consent=granted'));
        $this->assertSame([$ana->id], $ids('tag=vip'));
        $this->assertCount(2, $ids('search='));
        $this->assertSame([], $ids('search=nadie'));
    }

    public function test_a_customer_is_deactivated_not_deleted_and_the_change_is_audited_without_personal_data(): void
    {
        $this->post('/customers', $this->payload(['notes' => 'Prefiere la tarde']));
        $customer = Customer::firstOrFail();

        $this->put("/customers/{$customer->id}", $this->payload(['first_name' => 'Laura Sofía', 'phone' => '+573207778899', 'notes' => 'Otra nota']))->assertSessionHasNoErrors();
        $this->post("/customers/{$customer->id}/deactivate")->assertSessionHas('success');
        $this->assertFalse($customer->fresh()->is_active);
        $this->post("/customers/{$customer->id}/activate");
        $this->assertTrue($customer->fresh()->is_active);

        $logs = AuditLog::where('resource_type', 'customer')->get();
        $this->assertTrue($logs->contains('action', 'created'));
        $changes = $logs->where('action', 'updated')->flatMap(fn ($log) => $log->changes);
        $this->assertTrue($changes->contains(fn ($c) => $c['field'] === 'Nombre'), 'the name change is audited');
        $this->assertTrue($changes->contains(fn ($c) => $c['field'] === 'Teléfono'), 'the phone change is audited');
        $this->assertStringNotContainsString('Laura', json_encode($logs->map->changes));
        $this->assertSame(['#'.$customer->id], $logs->pluck('resource_label')->unique()->values()->all(), 'a customer is identified by id in the audit log');
        $this->assertStringNotContainsString('3207778899', json_encode($logs->map->changes));
        $this->assertStringNotContainsString('Otra nota', json_encode($logs->map->changes));
    }

    public function test_reading_and_managing_are_separate_permissions(): void
    {
        $this->actAsMember($this->workspace, 'cliente');
        $this->get('/customers')->assertForbidden();

        Role::findByName('cliente', 'web')->givePermissionTo('view-customers');
        $this->get('/customers')->assertOk();
        $this->post('/customers', $this->payload())->assertForbidden();

        Role::findByName('cliente', 'web')->givePermissionTo('create-customers');
        $this->post('/customers', $this->payload())->assertSessionHasNoErrors();
    }

    public function test_a_workspace_without_the_module_in_its_plan_cannot_open_it_even_with_the_permission(): void
    {
        $without = $this->bookingWorkspace('SINCLIENTES', ['dashboard']);
        $this->actAsMember($without);

        $this->get('/customers')->assertForbidden();
        $this->post('/customers', $this->payload())->assertForbidden();
        $this->assertSame(0, $without->customers()->count());
    }

    public function test_conversations_and_appointments_are_related_only_when_their_modules_are_available(): void
    {
        $customer = $this->customer($this->workspace, ['first_name' => 'Laura', 'phone' => '+57 300 111 2233', 'email' => 'laura@example.com']);
        $customer->identities()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);

        // Only Clientes in the plan: neither list is offered (null, not an empty list).
        $this->get("/customers/{$customer->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('conversations', null)->where('appointments', null));

        $this->contractModules($this->workspace, ['dashboard', 'customers', 'conversations', 'service_catalog', 'professionals', 'bookings']);
        $bot = $this->workspace->chatbots()->create(['name' => 'Asistente', 'instructions' => 'Sé breve']);
        $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233']);
        $bot->conversations()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'contact_id' => '573009999999']);
        $this->freezeAgendaTime();
        $service = $this->makeService($this->workspace);
        $professional = $this->makeProfessional($this->workspace, [$service]);
        $this->appointments()->book($this->workspace, $service, $professional->id, CarbonImmutable::parse('2026-10-13 10:00:00', 'UTC'), ['name' => 'Laura Pérez', 'phone' => '+57 300 111 2233', 'email' => 'otro@example.com'], 'admin');
        $this->appointments()->book($this->workspace, $service, $professional->id, CarbonImmutable::parse('2026-10-13 12:00:00', 'UTC'), ['name' => 'Desconocida', 'phone' => '+573150000000', 'email' => 'nadie@example.com'], 'admin');

        $this->get("/customers/{$customer->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 1)->has('appointments', 1));

        // Without the permission of the module, the role does not see that part either.
        Role::findByName('admin', 'web')->revokePermissionTo('view-conversations');
        $this->get("/customers/{$customer->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('conversations', null)->has('appointments', 1));
    }

    public function test_the_conversations_of_another_workspace_with_the_same_channel_id_are_not_shown(): void
    {
        $customer = $this->customer($this->workspace, ['first_name' => 'Laura', 'phone' => '+573001112233']);
        $customer->identities()->create(['workspace_id' => $this->workspace->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);
        $this->contractModules($this->workspace, ['dashboard', 'customers', 'conversations']);

        $bot = $this->other->chatbots()->create(['name' => 'Ajeno', 'instructions' => 'x']);
        $bot->conversations()->create(['workspace_id' => $this->other->id, 'channel' => 'whatsapp', 'contact_id' => '573001112233']);

        $this->get("/customers/{$customer->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 0));
    }
}
