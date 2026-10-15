<?php

namespace Tests\Feature;

use App\Campaigns\CampaignDispatcher;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/** Campañas: templates with validated variables, audience selection with exclusions, preparation, simulation (never a real send), permissions, plan and isolation. */
class CampaignsTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->workspace = $this->bookingWorkspace('TIENDA', ['dashboard', 'customers', 'campaigns']);
        $this->other = $this->bookingWorkspace('OTRA', ['dashboard', 'customers', 'campaigns']);
        $this->actAsMember($this->workspace);
    }

    private function customer(Workspace $workspace, string $name, array $attributes = []): Customer
    {
        static $n = 3000000000;

        return $workspace->customers()->create($attributes + ['first_name' => $name, 'phone' => (string) $n++, 'email' => strtolower($name).$n.'@x.co', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'ok'])->fresh();
    }

    private function draft(array $overrides = [], ?Workspace $workspace = null): Campaign
    {
        $workspace ??= $this->workspace;

        return $workspace->campaigns()->create($overrides + ['name' => 'Octubre', 'body' => 'Hola {{nombre}}', 'channel' => 'whatsapp', 'audience' => 'all'])->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Promo', 'body' => 'Hola {{nombre}}, tenemos algo para ti', 'channel' => 'whatsapp', 'audience' => 'all'];
    }

    private function exclusions(Campaign $campaign): array
    {
        return $campaign->recipients()->where('status', 'excluded')->pluck('exclusion_reason', 'customer_id')->all();
    }

    // --- templates -------------------------------------------------------------------------------------------------

    public function test_a_template_is_created_edited_deactivated_and_audited(): void
    {
        $this->post('/campaigns/templates', ['name' => 'Bienvenida', 'category' => 'General', 'body' => 'Hola {{ nombre }} {{apellido}}'])->assertSessionHasNoErrors();
        $template = $this->workspace->messageTemplates()->firstOrFail();

        $this->put("/campaigns/templates/{$template->id}", ['name' => 'Bienvenida 2', 'body' => 'Hola {{nombre_completo}}'])->assertSessionHasNoErrors();
        $this->assertSame('Bienvenida 2', $template->fresh()->name);
        $this->post("/campaigns/templates/{$template->id}/deactivate");
        $this->assertFalse($template->fresh()->is_active);
        $this->post("/campaigns/templates/{$template->id}/activate");
        $this->assertTrue($template->fresh()->is_active);

        $this->assertTrue(AuditLog::where('resource_type', 'message_template')->where('action', 'created')->exists());
        $this->assertTrue(AuditLog::where('resource_type', 'message_template')->where('action', 'updated')->exists());
    }

    public function test_a_template_only_accepts_known_variables_and_unique_names(): void
    {
        $this->post('/campaigns/templates', ['name' => 'Mala', 'body' => 'Hola {{nombre}} {{saldo}}'])->assertSessionHasErrors('body');
        $this->assertStringContainsString('{{saldo}}', session('errors')->first('body'));
        $this->post('/campaigns/templates', ['name' => 'Vacía', 'body' => ''])->assertSessionHasErrors('body');
        $this->post('/campaigns/templates', ['name' => 'Larga', 'body' => str_repeat('a', 1001)])->assertSessionHasErrors('body');

        $this->post('/campaigns/templates', ['name' => 'Una', 'body' => 'Hola']);
        $this->post('/campaigns/templates', ['name' => 'Una', 'body' => 'Hola otra vez'])->assertSessionHasErrors('name');
        // The same name is fine in another Workspace.
        $this->other->messageTemplates()->create(['name' => 'Una', 'body' => 'x']);
        $this->assertSame(1, $this->workspace->messageTemplates()->count());
    }

    public function test_the_index_reports_which_customers_have_the_data_of_each_variable(): void
    {
        $this->customer($this->workspace, 'Ana', ['last_name' => 'Gómez']);
        $this->customer($this->workspace, 'Beto', ['phone' => null, 'email' => null]);

        $this->get('/campaigns')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Campaigns/Index')
            ->where('availability.nombre', ['label' => 'Nombre', 'available' => 2, 'total' => 2])
            ->where('availability.apellido.available', 1)
            ->where('availability.telefono.available', 1)
            ->where('availability.correo.available', 1)
            ->has('sample', 2));
    }

    // --- campaigns: draft ------------------------------------------------------------------------------------------

    public function test_a_campaign_is_created_as_a_draft_with_a_snapshot_of_its_message(): void
    {
        $template = $this->workspace->messageTemplates()->create(['name' => 'Base', 'body' => 'Hola {{nombre}}']);

        $this->post('/campaigns', $this->payload(['message_template_id' => $template->id, 'body' => 'Hola {{nombre}}']))->assertSessionHasNoErrors();
        $campaign = $this->workspace->campaigns()->firstOrFail();
        $this->assertSame(['draft', 'Hola {{nombre}}'], [$campaign->status, $campaign->body]);

        $template->update(['body' => 'Cambiada']);
        $this->assertSame('Hola {{nombre}}', $campaign->fresh()->body, 'editing the template never changes a campaign');
        $this->assertTrue(AuditLog::where('resource_type', 'campaign')->where('action', 'created')->exists());
    }

    public function test_a_draft_only_accepts_valid_data_of_its_own_workspace(): void
    {
        $foreignTemplate = $this->other->messageTemplates()->create(['name' => 'Ajena', 'body' => 'x']);
        $foreignCustomer = $this->customer($this->other, 'Ajena');

        $this->post('/campaigns', $this->payload(['message_template_id' => $foreignTemplate->id]))->assertSessionHasErrors('message_template_id');
        $this->post('/campaigns', $this->payload(['audience' => 'selected', 'customer_ids' => [$foreignCustomer->id]]))->assertSessionHasErrors('customer_ids.0');
        $this->post('/campaigns', $this->payload(['body' => 'Hola {{nada}}']))->assertSessionHasErrors('body');
        $this->post('/campaigns', $this->payload(['channel' => 'sms']))->assertSessionHasErrors('channel');
        $this->post('/campaigns', $this->payload(['audience' => 'todos']))->assertSessionHasErrors('audience');
        $this->post('/campaigns', $this->payload(['filters' => ['source' => 'inventado']]))->assertSessionHasErrors('filters.source');
        $this->assertSame(0, Campaign::count());
    }

    public function test_the_audience_stores_either_filters_or_a_hand_picked_list(): void
    {
        $ana = $this->customer($this->workspace, 'Ana');
        $this->post('/campaigns', $this->payload(['audience' => 'selected', 'customer_ids' => [$ana->id, $ana->id], 'filters' => ['tag' => 'vip']]));
        $selected = Campaign::firstOrFail();
        $this->assertSame([$ana->id], $selected->customer_ids);
        $this->assertNull($selected->filters);

        $this->put("/campaigns/{$selected->id}", $this->payload(['audience' => 'all', 'filters' => ['tag' => 'vip', 'source' => '', 'junk' => 'x'], 'customer_ids' => [$ana->id]]));
        $all = $selected->fresh();
        $this->assertSame(['tag' => 'vip'], $all->filters);
        $this->assertNull($all->customer_ids);
    }

    // --- eligibility and exclusions --------------------------------------------------------------------------------

    public function test_only_customers_with_consent_data_and_contact_are_eligible_and_the_rest_say_why(): void
    {
        $ok = $this->customer($this->workspace, 'Ok');
        $unknown = $this->customer($this->workspace, 'Unknown', ['marketing_consent' => 'unknown', 'consent_note' => null]);
        $revoked = $this->customer($this->workspace, 'Revoked', ['marketing_consent' => 'revoked']);
        $inactive = $this->customer($this->workspace, 'Inactive', ['is_active' => false]);
        $noPhone = $this->customer($this->workspace, 'NoPhone', ['phone' => null]);
        $noName = $this->customer($this->workspace, 'Nameless', ['last_name' => null]);
        $campaign = $this->draft(['body' => 'Hola {{nombre}} {{apellido}}']);
        $noName->update(['last_name' => null]);
        $ok->update(['last_name' => 'Pérez']);
        $unknown->update(['last_name' => 'U']);
        $revoked->update(['last_name' => 'R']);
        $inactive->update(['last_name' => 'I']);
        $noPhone->update(['last_name' => 'N']);

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();

        $this->assertEquals([
            $unknown->id => 'no_consent',
            $revoked->id => 'opted_out',
            $inactive->id => 'inactive',
            $noPhone->id => 'no_contact',
            $noName->id => 'missing_variable',
        ], $this->exclusions($campaign));
        $this->assertSame('pending', $campaign->recipients()->where('customer_id', $ok->id)->value('status'));
    }

    public function test_the_channel_decides_which_contact_datum_is_needed(): void
    {
        $onlyEmail = $this->customer($this->workspace, 'Mail', ['phone' => null]);
        $onlyPhone = $this->customer($this->workspace, 'Tel', ['email' => null]);
        $campaign = $this->draft(['channel' => 'email', 'body' => 'Hola']);

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();

        $this->assertSame([$onlyPhone->id => 'no_contact'], $this->exclusions($campaign));
        $this->assertSame('pending', $campaign->recipients()->where('customer_id', $onlyEmail->id)->value('status'));
    }

    public function test_all_eligible_customers_means_the_active_workspace_only_and_filters_narrow_it(): void
    {
        $vip = $this->customer($this->workspace, 'Vip', ['source' => 'booking']);
        $vip->tags()->create(['workspace_id' => $this->workspace->id, 'tag' => 'vip']);
        $plain = $this->customer($this->workspace, 'Plain');
        $foreign = $this->customer($this->other, 'Foreign');

        $everyone = $this->draft();
        $this->post("/campaigns/{$everyone->id}/prepare");
        $this->assertEqualsCanonicalizing([$vip->id, $plain->id], $everyone->recipients()->pluck('customer_id')->all());
        $this->assertFalse($everyone->recipients()->where('customer_id', $foreign->id)->exists());

        $byTag = $this->draft(['name' => 'VIP', 'filters' => ['tag' => 'vip']]);
        $this->post("/campaigns/{$byTag->id}/prepare");
        $this->assertSame([$vip->id], $byTag->recipients()->pluck('customer_id')->all());

        $bySource = $this->draft(['name' => 'Reservas', 'filters' => ['source' => 'booking']]);
        $this->post("/campaigns/{$bySource->id}/prepare");
        $this->assertSame([$vip->id], $bySource->recipients()->pluck('customer_id')->all());
    }

    public function test_a_hand_picked_list_never_reaches_an_ineligible_or_foreign_customer(): void
    {
        $ok = $this->customer($this->workspace, 'Ok');
        $revoked = $this->customer($this->workspace, 'Revoked', ['marketing_consent' => 'revoked']);
        $foreign = $this->customer($this->other, 'Foreign');
        // Ids tampered directly in the database (the form would refuse them) are still never addressed.
        $campaign = $this->draft(['audience' => 'selected', 'customer_ids' => [$ok->id, $revoked->id, $foreign->id]]);

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$ok->id, $revoked->id], $campaign->recipients()->pluck('customer_id')->all());
        $this->assertSame([$revoked->id => 'opted_out'], $this->exclusions($campaign));
    }

    public function test_the_draft_review_shows_totals_and_the_picker_flags_who_cannot_be_picked(): void
    {
        $this->customer($this->workspace, 'Ok');
        $this->customer($this->workspace, 'Baja', ['marketing_consent' => 'revoked']);
        $campaign = $this->draft(['audience' => 'selected']);

        $this->get("/campaigns/{$campaign->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Campaigns/Show')
            ->where('summary', ['total' => 0, 'eligible' => 0, 'excluded' => []])
            ->has('picker.data', 2)
            ->where('picker.data.0.reason', 'opted_out')
            ->where('picker.data.1.reason', null)
            ->where('delivery.available', false));

        $campaign->update(['audience' => 'all']);
        $this->get("/campaigns/{$campaign->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total', 2)->where('summary.eligible', 1)->where('summary.excluded', ['opted_out' => 1])->where('picker', null));
    }

    // --- prepare / simulate / cancel -------------------------------------------------------------------------------

    public function test_preparing_fixes_the_audience_and_the_campaign_is_no_longer_editable(): void
    {
        $ana = $this->customer($this->workspace, 'Ana');
        $campaign = $this->draft();

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();
        $this->assertSame('prepared', $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->prepared_at);

        // A customer added afterwards is not part of the fixed audience.
        $this->customer($this->workspace, 'Beto');
        $this->assertSame([$ana->id], $campaign->recipients()->pluck('customer_id')->all());

        $this->put("/campaigns/{$campaign->id}", $this->payload(['name' => 'Cambio']))->assertSessionHasErrors('status');
        $this->assertSame('Octubre', $campaign->fresh()->name);
        $this->assertTrue(AuditLog::where('resource_type', 'campaign')->where('action', 'prepared')->exists());
    }

    public function test_a_campaign_with_nobody_eligible_cannot_be_prepared(): void
    {
        $this->customer($this->workspace, 'Baja', ['marketing_consent' => 'revoked']);
        $campaign = $this->draft();

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasErrors('status');

        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertSame(0, $campaign->recipients()->count(), 'a refused preparation leaves no recipients behind');
    }

    public function test_preparing_twice_never_duplicates_recipients(): void
    {
        $this->customer($this->workspace, 'Ana');
        $campaign = $this->draft();

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();
        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasErrors('status');

        $this->assertSame(1, $campaign->recipients()->count());
    }

    public function test_the_database_refuses_a_customer_twice_in_the_same_campaign(): void
    {
        $ana = $this->customer($this->workspace, 'Ana');
        $campaign = $this->draft();
        $campaign->recipients()->create(['workspace_id' => $this->workspace->id, 'customer_id' => $ana->id, 'status' => 'pending']);

        $this->expectException(UniqueConstraintViolationException::class);
        $campaign->recipients()->create(['workspace_id' => $this->workspace->id, 'customer_id' => $ana->id, 'status' => 'pending']);
    }

    public function test_simulating_records_a_dry_run_once_and_sends_nothing(): void
    {
        Http::fake();
        $this->customer($this->workspace, 'Ana');
        $this->customer($this->workspace, 'Baja', ['marketing_consent' => 'revoked']);
        $campaign = $this->draft();

        $this->post("/campaigns/{$campaign->id}/simulate")->assertSessionHasErrors('status');
        $this->assertSame('draft', $campaign->fresh()->status);

        $this->post("/campaigns/{$campaign->id}/prepare");
        $this->post("/campaigns/{$campaign->id}/simulate")->assertSessionHasNoErrors();

        $fresh = $campaign->fresh();
        $this->assertSame('simulated', $fresh->status);
        $this->assertNotNull($fresh->simulated_at);
        $this->assertSame(['excluded' => 1, 'simulated' => 1], $campaign->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->orderBy('status')->pluck('total', 'status')->all());
        $this->assertSame(0, $campaign->recipients()->where('status', 'sent')->count());
        Http::assertNothingSent();

        $this->post("/campaigns/{$campaign->id}/simulate")->assertSessionHasErrors('status');
        $this->assertTrue(AuditLog::where('resource_type', 'campaign')->where('action', 'simulated')->exists());
    }

    public function test_no_campaign_can_be_sent_for_real_and_the_interface_says_so(): void
    {
        $this->assertFalse(app(CampaignDispatcher::class)->availability($this->draft())['available']);
        $this->assertArrayNotHasKey('sent', config('campaigns.statuses'));
        $this->assertSame([], collect(\Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'campaigns.') && str_contains($name, 'send'))->all());

        $campaign = $this->draft();
        $this->get("/campaigns/{$campaign->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('delivery.available', false)->has('delivery.reason'));
    }

    public function test_a_draft_or_prepared_campaign_can_be_cancelled_but_not_a_finished_one(): void
    {
        $this->customer($this->workspace, 'Ana');
        $draft = $this->draft();
        $this->post("/campaigns/{$draft->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->post("/campaigns/{$draft->id}/cancel")->assertSessionHasErrors('status');
        $this->post("/campaigns/{$draft->id}/prepare")->assertSessionHasErrors('status');

        $prepared = $this->draft(['name' => 'Otra']);
        $this->post("/campaigns/{$prepared->id}/prepare");
        $this->post("/campaigns/{$prepared->id}/cancel")->assertSessionHasNoErrors();

        $simulated = $this->draft(['name' => 'Tercera']);
        $this->post("/campaigns/{$simulated->id}/prepare");
        $this->post("/campaigns/{$simulated->id}/simulate");
        $this->post("/campaigns/{$simulated->id}/cancel")->assertSessionHasErrors('status');
        $this->assertSame('simulated', $simulated->fresh()->status);
    }

    // --- isolation, permissions, plan ------------------------------------------------------------------------------

    public function test_campaigns_and_templates_of_another_workspace_are_unreachable(): void
    {
        $foreign = $this->draft([], $this->other);
        $template = $this->other->messageTemplates()->create(['name' => 'Ajena', 'body' => 'x']);

        $this->get("/campaigns/{$foreign->id}")->assertNotFound();
        $this->put("/campaigns/{$foreign->id}", $this->payload())->assertNotFound();
        foreach (['prepare', 'simulate', 'cancel'] as $action) {
            $this->post("/campaigns/{$foreign->id}/{$action}")->assertNotFound();
        }
        $this->put("/campaigns/templates/{$template->id}", ['name' => 'X', 'body' => 'x'])->assertNotFound();
        $this->post("/campaigns/templates/{$template->id}/deactivate")->assertNotFound();
        $this->assertSame('draft', $foreign->fresh()->status);

        $this->get('/campaigns')->assertInertia(fn (AssertableInertia $page) => $page->where('campaigns.meta.total', 0)->has('templates', 0));
    }

    public function test_reading_and_managing_are_separate_permissions(): void
    {
        $this->actAsMember($this->workspace, 'cliente');
        $this->get('/campaigns')->assertForbidden();

        Role::findByName('cliente', 'web')->givePermissionTo('view-campaigns');
        $campaign = $this->draft();
        $this->get('/campaigns')->assertOk();
        $this->get("/campaigns/{$campaign->id}")->assertOk();
        $this->post('/campaigns', $this->payload())->assertForbidden();
        $this->post('/campaigns/templates', ['name' => 'X', 'body' => 'x'])->assertForbidden();
        foreach (['prepare', 'simulate', 'cancel'] as $action) {
            $this->post("/campaigns/{$campaign->id}/{$action}")->assertForbidden();
        }
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_campaigns_need_customers_in_the_plan_and_a_plan_without_them_has_neither(): void
    {
        $onlyCampaigns = $this->bookingWorkspace('SOLOCAMP', ['dashboard', 'campaigns']);
        $this->assertSame(['dashboard'], app(ModuleAccess::class)->keys($onlyCampaigns), 'a module with a missing dependency is not operative');

        $this->actAsMember($onlyCampaigns);
        $this->get('/campaigns')->assertForbidden();
        $this->get('/customers')->assertForbidden();

        $without = $this->bookingWorkspace('NADA', ['dashboard']);
        $this->actAsMember($without);
        $this->get('/campaigns')->assertForbidden();
        $this->post('/campaigns', $this->payload())->assertForbidden();
    }

    public function test_the_new_modules_are_outside_the_default_plan_and_the_administrative_workspace_keeps_them(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\ModuleCatalogSeeder']);
        $default = Plan::where('key', 'full_platform')->firstOrFail();
        $keys = $default->modules()->pluck('modules.key')->all();
        $this->assertNotContains('customers', $keys);
        $this->assertNotContains('campaigns', $keys);

        $client = $this->workspace->fresh();
        $client->update(['plan_id' => null]);
        $this->assertNotContains('customers', app(ModuleAccess::class)->keys($client));

        $organization = $this->workspace->organization;
        $dev = Workspace::create(['organization_id' => $organization->id, 'code' => 'DESARROLLO_DEV', 'name' => 'Dev']);
        $this->assertContains('customers', app(ModuleAccess::class)->keys($dev));
        $this->assertContains('campaigns', app(ModuleAccess::class)->keys($dev));
    }

    public function test_a_registered_but_unimplemented_module_is_never_operative_just_because_a_plan_lists_it(): void
    {
        $this->post('/plans', ['name' => 'X']); // not a platform admin here: refused, nothing created
        $module = Module::create(['key' => 'loyalty', 'name' => 'Fidelización', 'module_category_id' => ModuleCategory::where('key', 'communication')->value('id')]);
        $plan = Plan::create(['name' => 'Con pendiente']);
        $plan->modules()->sync([$module->id, Module::where('key', 'customers')->value('id')]);
        $this->workspace->update(['plan_id' => $plan->id]);

        $keys = app(ModuleAccess::class)->keys($this->workspace->fresh());

        $this->assertContains('customers', $keys);
        $this->assertNotContains('loyalty', $keys);
        $this->assertFalse($module->isImplemented());
    }

    public function test_the_shared_props_only_list_the_modules_the_plan_includes(): void
    {
        $this->get('/campaigns')->assertInertia(fn (AssertableInertia $page) => $page->where('modules', fn ($modules) => collect($modules)->sort()->values()->all() === ['campaigns', 'customers', 'dashboard']));
    }

    public function test_the_templates_a_campaign_can_load_are_only_the_active_ones_of_the_workspace(): void
    {
        $this->workspace->messageTemplates()->create(['name' => 'Activa', 'body' => 'a']);
        $this->workspace->messageTemplates()->create(['name' => 'Inactiva', 'body' => 'b', 'is_active' => false]);
        $this->other->messageTemplates()->create(['name' => 'Ajena', 'body' => 'c']);
        $campaign = $this->draft();

        $this->get("/campaigns/{$campaign->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('templates', 1)->where('templates.0.name', 'Activa'));
        $inactive = MessageTemplate::where('name', 'Inactiva')->firstOrFail();
        $this->post('/campaigns', $this->payload(['message_template_id' => $inactive->id]))->assertSessionHasErrors('message_template_id');
    }
}
