<?php

namespace Tests\Feature;

use App\Customers\CustomerTagCatalog;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerTagDefinition;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** «Etiquetas» of Clientes: one catalog per Workspace, no duplicates by capitalization, spaces or Unicode form, safe delete, isolation and permissions. */
class CustomerTagCatalogTest extends TestCase
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
        return array_merge(['first_name' => 'Laura', 'phone' => '+57 300 111 2233', 'source' => 'manual', 'marketing_consent' => 'unknown'], $overrides);
    }

    private function tag(Workspace $workspace, string $name, bool $active = true): CustomerTagDefinition
    {
        return CustomerTagDefinition::create(['workspace_id' => $workspace->id, 'name' => $name, 'normalized_name' => CustomerTagCatalog::normalize($name), 'is_active' => $active]);
    }

    public function test_the_same_tag_in_another_capitalization_spacing_or_unicode_form_is_refused(): void
    {
        $this->post('/customers/tags', ['name' => 'VIP'])->assertSessionHasNoErrors();

        foreach (['Vip', '  vip ', 'vIp'] as $variant) {
            $this->post('/customers/tags', ['name' => $variant])->assertSessionHasErrors('name');
        }

        $composed = "Caf\u{00E9} frecuente";
        $decomposed = "Cafe\u{0301}   frecuente";
        $this->post('/customers/tags', ['name' => $composed])->assertSessionHasNoErrors();
        $this->post('/customers/tags', ['name' => $decomposed])->assertSessionHasErrors('name');

        $this->assertSame(2, CustomerTagDefinition::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(mb_strtolower($composed), CustomerTagDefinition::where('normalized_name', mb_strtolower($composed))->value('name'), 'the stored name is the NFC, lower case form');
    }

    public function test_a_tag_that_differs_only_by_an_accent_is_a_different_tag_listed_as_a_possible_duplicate(): void
    {
        $this->post('/customers/tags', ['name' => 'via'])->assertSessionHasNoErrors();
        $this->post('/customers/tags', ['name' => 'vía'])->assertSessionHasNoErrors();

        $this->assertSame(2, CustomerTagDefinition::where('workspace_id', $this->workspace->id)->count(), 'Ava never merges them by itself');
        $this->get('/customers?tab=etiquetas')->assertInertia(fn ($page) => $page->where('tagAdmin.possibleDuplicates', fn ($groups) => collect($groups)->flatten()->sort()->values()->all() === ['via', 'vía']));
    }

    public function test_the_database_refuses_two_tags_with_the_same_normalized_name_even_when_the_checks_race(): void
    {
        $this->tag($this->workspace, 'vip');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('customer_tag_definitions')->insert(['workspace_id' => $this->workspace->id, 'name' => 'VIP', 'normalized_name' => 'vip', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_simultaneous_creation_resolves_to_the_tag_that_won(): void
    {
        $catalog = app(CustomerTagCatalog::class);
        [$first, $created] = $catalog->ensure($this->workspace, 'Mayorista');
        [$second, $createdAgain] = $catalog->ensure($this->workspace, ' mayorista');

        $this->assertTrue($created);
        $this->assertFalse($createdAgain);
        $this->assertSame($first->id, $second->id);
    }

    public function test_the_same_name_in_two_workspaces_is_two_independent_tags(): void
    {
        $this->post('/customers/tags', ['name' => 'vip'])->assertSessionHasNoErrors();
        $mine = CustomerTagDefinition::where('workspace_id', $this->workspace->id)->firstOrFail();

        $this->actAsMember($this->other);
        $this->post('/customers/tags', ['name' => 'vip'])->assertSessionHasNoErrors();

        // Another Workspace can neither read nor change the tag of the first one.
        $this->put("/customers/tags/{$mine->id}", ['name' => 'robada'])->assertNotFound();
        $this->post("/customers/tags/{$mine->id}/deactivate")->assertNotFound();
        $this->delete("/customers/tags/{$mine->id}")->assertNotFound();
        $this->get('/customers?tab=etiquetas')->assertInertia(fn ($page) => $page->has('tagAdmin.data', 1)->where('tagAdmin.data.0.name', 'vip')->where('tagAdmin.data.0.customers', 0));

        $this->assertSame('vip', $mine->fresh()->name);
        $this->assertTrue($mine->fresh()->is_active);
    }

    public function test_a_tag_typed_in_the_customer_form_goes_to_the_same_catalog_and_is_not_duplicated(): void
    {
        $this->post('/customers/tags', ['name' => 'VIP'])->assertSessionHasNoErrors();
        $this->post('/customers', $this->payload(['tags' => ['vip', 'Nuevo', ' nuevo ']]))->assertSessionHasNoErrors();

        $customer = $this->workspace->customers()->with('tags')->firstOrFail();
        $this->assertEqualsCanonicalizing(['vip', 'nuevo'], $customer->tags->pluck('tag')->all());
        $this->assertSame(2, CustomerTagDefinition::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame($customer->tags->pluck('tag_id')->sort()->values()->all(), CustomerTagDefinition::pluck('id')->sort()->values()->all());
        $this->assertSame(1, AuditLog::where('resource_type', 'customer_tag')->where('action', 'created')->where('resource_label', 'nuevo')->count(), 'the tag created from the form is audited once');
    }

    public function test_renaming_keeps_every_assignment_and_updates_the_name_everywhere(): void
    {
        $this->post('/customers', $this->payload(['tags' => ['vip']]))->assertSessionHasNoErrors();
        $this->post('/customers', $this->payload(['phone' => '+57 300 111 9999', 'tags' => ['vip']]))->assertSessionHasNoErrors();
        $tag = CustomerTagDefinition::firstOrFail();

        $this->put("/customers/tags/{$tag->id}", ['name' => 'Cliente VIP'])->assertSessionHasNoErrors();

        $this->assertSame(['cliente vip', 'cliente vip'], Customer::with('tags')->get()->flatMap->tags->pluck('tag')->all());
        $this->assertSame(2, $tag->assignments()->count());
        $this->get('/customers?tag=Cliente%20VIP')->assertInertia(fn ($page) => $page->has('customers.data', 2));

        $this->post('/customers/tags', ['name' => 'otra'])->assertSessionHasNoErrors();
        $this->put('/customers/tags/'.CustomerTagDefinition::where('name', 'otra')->value('id'), ['name' => ' cliente   vip'])->assertSessionHasErrors('name');
    }

    public function test_a_tag_with_customers_is_deactivated_never_deleted_and_the_customers_keep_it(): void
    {
        $this->post('/customers', $this->payload(['tags' => ['vip']]))->assertSessionHasNoErrors();
        $tag = CustomerTagDefinition::firstOrFail();

        $this->delete("/customers/tags/{$tag->id}")->assertSessionHasErrors('tag');
        $this->assertNotNull($tag->fresh());

        $this->post("/customers/tags/{$tag->id}/deactivate")->assertSessionHasNoErrors();
        $customer = $this->workspace->customers()->firstOrFail();

        // It is not offered any more...
        $this->get('/customers')->assertInertia(fn ($page) => $page->where('options.tagCatalog', []));
        $this->post('/customers', $this->payload(['phone' => '+57 300 111 7777', 'tags' => ['vip']]))->assertSessionHasErrors('tags');
        // ...but the customer who has it still shows it, can be edited and keeps it.
        $this->get("/customers/{$customer->id}")->assertInertia(fn ($page) => $page->where('customer.tags', ['vip']));
        $this->put("/customers/{$customer->id}", $this->payload(['first_name' => 'Laura M', 'tags' => ['vip']]))->assertSessionHasNoErrors();
        $this->assertSame(['vip'], $customer->fresh()->tags->pluck('tag')->all());

        $this->post("/customers/tags/{$tag->id}/activate")->assertSessionHasNoErrors();
        $this->assertTrue($tag->fresh()->is_active);
    }

    public function test_an_unused_tag_can_be_deleted_unless_a_campaign_filters_by_it(): void
    {
        $free = $this->tag($this->workspace, 'sobrante');
        $named = $this->tag($this->workspace, 'promo');
        $this->workspace->campaigns()->create(['name' => 'Septiembre', 'body' => 'Hola', 'channel' => 'whatsapp', 'audience' => 'all', 'filters' => ['tag' => 'PROMO']]);

        $this->delete("/customers/tags/{$free->id}")->assertSessionHasNoErrors();
        $this->assertNull($free->fresh());
        $this->assertSame(1, AuditLog::where('resource_type', 'customer_tag')->where('action', 'deleted')->count());

        $this->delete("/customers/tags/{$named->id}")->assertSessionHasErrors('tag');
        $this->assertNotNull($named->fresh());
    }

    public function test_the_database_refuses_to_delete_a_tag_that_is_assigned(): void
    {
        $this->post('/customers', $this->payload(['tags' => ['vip']]))->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);
        DB::table('customer_tag_definitions')->delete();
    }

    public function test_a_customer_can_not_be_given_a_tag_of_another_workspace(): void
    {
        $foreign = $this->tag($this->other, 'ajena');
        $customer = $this->workspace->customers()->create(['first_name' => 'Ana', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown']);

        $this->expectException(QueryException::class);
        DB::table('customer_tags')->insert(['workspace_id' => $this->workspace->id, 'customer_id' => $customer->id, 'tag_id' => $foreign->id, 'tag' => 'ajena']);
    }

    public function test_the_catalog_has_the_permissions_of_customers(): void
    {
        $viewer = $this->actAsMember($this->workspace, 'cliente');
        Role::findByName('cliente', 'web')->givePermissionTo('view-customers');
        $tag = $this->tag($this->workspace, 'vip');

        $this->get('/customers?tab=etiquetas')->assertOk()->assertInertia(fn ($page) => $page->where('tagAdmin.canCreate', false)->where('tagAdmin.canEdit', false)->has('tagAdmin.data', 1));
        $this->post('/customers/tags', ['name' => 'nueva'])->assertForbidden();
        $this->put("/customers/tags/{$tag->id}", ['name' => 'otra'])->assertForbidden();
        $this->post("/customers/tags/{$tag->id}/deactivate")->assertForbidden();
        $this->delete("/customers/tags/{$tag->id}")->assertForbidden();

        Role::findByName('cliente', 'web')->givePermissionTo('create-customers');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->actingAs($viewer)->withSession(['workspace_id' => $this->workspace->id]);
        $this->post('/customers/tags', ['name' => 'nueva'])->assertSessionHasNoErrors();
        $this->put("/customers/tags/{$tag->id}", ['name' => 'otra'])->assertForbidden();
    }

    public function test_a_workspace_without_the_module_has_no_tag_routes(): void
    {
        $plain = $this->bookingWorkspace('SIN', ['dashboard']);
        $this->actAsMember($plain);

        $this->post('/customers/tags', ['name' => 'vip'])->assertForbidden();
        $this->assertSame(0, CustomerTagDefinition::where('workspace_id', $plain->id)->count());
    }

    public function test_the_migration_links_existing_tags_without_losing_or_merging_anything_and_down_restores_the_text(): void
    {
        $makeCustomer = fn (Workspace $ws, string $phone, array $tags) => tap($ws->customers()->create(['first_name' => 'C', 'phone' => $phone, 'source' => 'manual', 'marketing_consent' => 'unknown']), fn ($c) => $c->tags()->createMany(array_map(fn ($t) => ['workspace_id' => $ws->id, 'tag' => $t], $tags)));
        $a = $makeCustomer($this->workspace, '+573001110001', ['vip', 'via']);
        $b = $makeCustomer($this->workspace, '+573001110002', ['vip', "caf\u{00E9}"]);
        $c = $makeCustomer($this->other, '+573001110003', ['vip']);
        $d = $makeCustomer($this->workspace, '+573001110004', ["cafe\u{0301}"]); // the same word, decomposed: one tag after the migration
        $migration = require database_path('migrations/2026_11_03_100000_create_customer_tag_definitions.php');
        $before = DB::table('customer_tags')->orderBy('id')->pluck('tag', 'id')->all();

        $migration->down(); // the tests' schema already has the catalog: go back to the state before it (customers with plain-text tags)
        $this->assertSame($before, DB::table('customer_tags')->orderBy('id')->pluck('tag', 'id')->all(), 'down() leaves the text of every assignment untouched');
        $migration->up();

        $this->assertSame($before, DB::table('customer_tags')->orderBy('id')->pluck('tag', 'id')->all(), 'up() leaves the text of every assignment untouched');
        $this->assertSame(0, DB::table('customer_tags')->whereNull('tag_id')->count(), 'every assignment is linked');
        $names = fn (Workspace $ws) => DB::table('customer_tag_definitions')->where('workspace_id', $ws->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['café', 'via', 'vip'], $names($this->workspace), 'one tag per distinct normalized text: the composed and decomposed café are one, via and vía-style accent variants are never folded');
        $this->assertSame(['vip'], $names($this->other));
        $cafe = DB::table('customer_tags')->whereIn('customer_id', [$b->id, $d->id])->where('tag', 'like', 'caf%')->pluck('tag_id')->unique();
        $this->assertCount(1, $cafe);
        $this->assertNotSame(DB::table('customer_tags')->where('customer_id', $a->id)->where('tag', 'vip')->value('tag_id'), DB::table('customer_tags')->where('customer_id', $c->id)->value('tag_id'), 'a tag of another Workspace is another definition');
    }
}
