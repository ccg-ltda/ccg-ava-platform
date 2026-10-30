<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\SupportAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Privacy\Mask;
use App\Services\ModuleAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\Support\BuildsBookings;
use Tests\TestCase;

/**
 * Privacy of the final contacts of a client: a platform administrator (even a superuser) never reads another Workspace's conversations
 * or contacts without a justified, temporary, audited support access, and the private content never lands in the audit log.
 */
class PrivacyTest extends TestCase
{
    use BuildsBookings;
    use RefreshDatabase;

    private Workspace $dev;

    private Workspace $client;

    private Workspace $other;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleCatalog();
        $this->dev = $this->bookingWorkspace('DESARROLLO_DEV', []);
        $this->dev->update(['plan_id' => null]);
        $this->client = $this->bookingWorkspace('CLIENTE_UNO', ['dashboard', 'assistants', 'conversations', 'customers', 'campaigns']);
        $this->other = $this->bookingWorkspace('CLIENTE_DOS', ['dashboard', 'assistants', 'conversations', 'customers', 'campaigns']);
        $this->conversation = $this->conversationOf($this->client, '573001112233', 'Laura Pérez', 'secreto del cliente uno');
    }

    private function conversationOf(Workspace $workspace, string $contact, string $name, string $text): Conversation
    {
        $bot = $workspace->chatbots()->create(['name' => 'Asistente '.$workspace->code, 'instructions' => 'Sé breve']);
        $conversation = $bot->conversations()->create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'contact_id' => $contact, 'contact_name' => $name]);
        $conversation->forceFill(['last_message_at' => now()])->save();
        $conversation->messages()->create(['workspace_id' => $workspace->id, 'direction' => 'in', 'sender' => 'contact', 'type' => 'text', 'body' => $text, 'sent_at' => now()]);

        return $conversation;
    }

    private function signInDev(string $role = 'admin', bool $superuser = true): User
    {
        $user = $this->member($this->dev, $role);
        $user->forceFill(['is_superuser' => $superuser])->save();
        $this->actingAs($user)->withSession(['workspace_id' => $this->dev->id]);

        return $user;
    }

    private function grant(User $user, ?Workspace $workspace = null, int $minutes = 30): SupportAccess
    {
        return SupportAccess::create(['workspace_id' => ($workspace ?? $this->client)->id, 'user_id' => $user->id, 'data_type' => 'conversations', 'reason' => 'ticket 123: el asistente no responde', 'expires_at' => now()->addMinutes($minutes)]);
    }

    private function reason(): string
    {
        return 'Ticket 123: el cliente reporta que el asistente no responde';
    }

    // --- who may open a support access -----------------------------------------------------------------------------

    public function test_being_superuser_does_not_grant_access_to_private_data(): void
    {
        $this->signInDev('admin', true);

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.permissions', fn ($permissions) => ! in_array('access-private-data', $permissions->toArray(), true)));
        $this->post('/support-access', ['workspace_id' => $this->client->id, 'data_type' => 'conversations', 'reason' => $this->reason()])->assertForbidden();
        $this->assertSame(0, SupportAccess::count());
        $this->assertFalse(Role::findByName('admin', 'web')->hasPermissionTo('access-private-data'));
    }

    public function test_the_support_role_opens_a_grant_with_a_reason_and_it_is_audited_in_the_audited_workspace(): void
    {
        $user = $this->signInDev('soporte', true);

        $this->post('/support-access', ['workspace_id' => $this->client->id, 'data_type' => 'conversations', 'reason' => 'corto'])->assertSessionHasErrors('reason');
        $this->post('/support-access', ['workspace_id' => $this->client->id, 'data_type' => 'todo', 'reason' => $this->reason()])->assertSessionHasErrors('data_type');
        $this->post('/support-access', ['workspace_id' => $this->dev->id, 'data_type' => 'conversations', 'reason' => $this->reason()])->assertNotFound();
        $this->assertSame(0, SupportAccess::count());

        $this->post('/support-access', ['workspace_id' => $this->client->id, 'data_type' => 'conversations', 'reason' => $this->reason()])->assertRedirect();
        $grant = SupportAccess::firstOrFail();
        $this->assertSame([$user->id, $this->client->id], [$grant->user_id, $grant->workspace_id]);
        $this->assertTrue($grant->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)));

        $log = AuditLog::where('resource_type', 'support_access')->firstOrFail();
        $this->assertSame([$this->client->id, 'created', $user->email], [$log->workspace_id, $log->action, $log->user_email]);
        $this->assertContains(['field' => 'Motivo', 'before' => null, 'after' => $this->reason()], $log->changes);
    }

    public function test_the_permission_does_nothing_inside_a_client_workspace(): void
    {
        $user = $this->member($this->client, 'soporte');
        $user->forceFill(['is_superuser' => true])->save();
        $this->actingAs($user)->withSession(['workspace_id' => $this->client->id]);

        $this->post('/support-access', ['workspace_id' => $this->other->id, 'data_type' => 'conversations', 'reason' => $this->reason()])->assertForbidden();
        $this->assertSame(0, SupportAccess::count());
    }

    // --- the content of another workspace --------------------------------------------------------------------------

    public function test_without_a_grant_nobody_reads_the_conversations_of_another_workspace_not_even_a_superuser(): void
    {
        $this->signInDev('admin', true);

        $response = $this->get("/conversations?workspace={$this->client->id}");

        $response->assertInertia(fn (AssertableInertia $page) => $page->where('privateAccess.locked', true)->has('conversations', 0)->where('selected', null)->where('counts.ai', 1));
        $this->assertStringNotContainsString('secreto del cliente uno', $response->getContent());
        $this->assertStringNotContainsString('Laura', $response->getContent());
        $this->assertStringNotContainsString('573001112233', $response->getContent());
        $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}")->assertForbidden();
        $this->assertSame(0, AuditLog::where('action', 'accessed')->count());
    }

    public function test_a_grant_shows_masked_contacts_audits_every_read_and_never_stores_the_content(): void
    {
        $user = $this->signInDev('soporte', true);
        $this->grant($user);

        $page = $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}");

        $page->assertInertia(fn (AssertableInertia $p) => $p
            ->where('privateAccess.locked', false)->where('privateAccess.grant.reason', 'ticket 123: el asistente no responde')
            ->where('selected.items.0.body', 'secreto del cliente uno')->where('selected.contactId', '••••2233')->where('selected.contactName', null)
            ->where('conversations.0.contactId', '••••2233')->where('conversations.0.contactName', null)->where('selected.can.reply', false));
        $this->assertStringNotContainsString('Laura', $page->getContent());
        $this->assertStringNotContainsString('573001112233', $page->getContent());

        $reads = AuditLog::where('action', 'accessed')->get();
        $this->assertGreaterThanOrEqual(1, $reads->count());
        $this->assertTrue($reads->every(fn ($log) => $log->workspace_id === $this->client->id && $log->user_email === $user->email));
        $this->assertTrue($reads->contains(fn ($log) => $log->resource_id === $this->conversation->id));
        $this->assertContains(['field' => 'Motivo', 'before' => null, 'after' => 'ticket 123: el asistente no responde'], $reads->first()->changes);
        $dump = json_encode(AuditLog::all()->map->only(['resource_label', 'description', 'changes']));
        $this->assertStringNotContainsString('secreto del cliente uno', $dump);
        $this->assertStringNotContainsString('Laura', $dump);
        $this->assertStringNotContainsString('573001112233', $dump);
    }

    public function test_a_grant_cannot_be_used_to_search_for_a_contact(): void
    {
        $user = $this->signInDev('soporte', true);
        $this->grant($user);

        $this->get("/conversations?workspace={$this->client->id}&q=573001112233")->assertInertia(fn (AssertableInertia $page) => $page->where('filters.q', '')->has('conversations', 1));
        $this->get("/conversations?workspace={$this->client->id}&q=nadie")->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 1));
    }

    public function test_a_refreshing_page_does_not_flood_the_audit_log(): void
    {
        Cache::flush();
        $user = $this->signInDev('soporte', true);
        $this->grant($user);

        foreach (range(1, 4) as $ignored) {
            $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}")->assertOk();
        }

        $this->assertSame(1, AuditLog::where('action', 'accessed')->where('resource_id', $this->conversation->id)->count());
    }

    public function test_the_grant_covers_one_workspace_one_person_and_a_short_time(): void
    {
        $user = $this->signInDev('soporte', true);
        $grant = $this->grant($user);
        $foreign = $this->conversationOf($this->other, '573009998888', 'Otra Persona', 'secreto del cliente dos');

        // Another Workspace: still locked.
        $this->get("/conversations?workspace={$this->other->id}&c={$foreign->id}")->assertForbidden();
        $this->get("/conversations?workspace={$this->other->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('privateAccess.locked', true));

        // Another person with the same role: the grant is theirs only.
        $colleague = $this->member($this->dev, 'soporte');
        $colleague->forceFill(['is_superuser' => true])->save();
        $this->actingAs($colleague)->withSession(['workspace_id' => $this->dev->id]);
        $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}")->assertForbidden();

        // It expires.
        $this->actingAs($user)->withSession(['workspace_id' => $this->dev->id]);
        $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}")->assertOk();
        $this->travel(31)->minutes();
        $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}")->assertForbidden();
        $this->assertTrue($grant->fresh()->expires_at->isPast());
    }

    public function test_a_grant_can_be_closed_early_only_by_its_owner(): void
    {
        $user = $this->signInDev('soporte', true);
        $grant = $this->grant($user);

        $colleague = $this->member($this->dev, 'soporte');
        $colleague->forceFill(['is_superuser' => true])->save();
        $this->actingAs($colleague)->withSession(['workspace_id' => $this->dev->id]);
        $this->delete("/support-access/{$grant->id}")->assertNotFound();

        $this->actingAs($user)->withSession(['workspace_id' => $this->dev->id]);
        $this->delete("/support-access/{$grant->id}")->assertRedirect();
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->get("/conversations?workspace={$this->client->id}&c={$this->conversation->id}")->assertForbidden();
        $this->assertTrue(AuditLog::where('resource_type', 'support_access')->where('action', 'updated')->exists());
    }

    public function test_the_own_conversations_of_a_workspace_need_no_grant_and_are_not_masked(): void
    {
        $user = $this->member($this->client, 'admin');
        $this->actingAs($user)->withSession(['workspace_id' => $this->client->id]);

        $this->get("/conversations?c={$this->conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('privateAccess', null)->where('selected.contactId', '573001112233')->where('selected.contactName', 'Laura Pérez')->where('selected.items.0.body', 'secreto del cliente uno'));
        $this->assertSame(0, AuditLog::where('action', 'accessed')->count());
    }

    // --- aggregates, audit and staff data --------------------------------------------------------------------------

    public function test_the_dashboard_of_another_workspace_gives_counts_but_never_names_its_contacts(): void
    {
        $this->signInDev('admin', true);
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);

        $one = $this->get("/dashboard?workspace={$this->client->id}")->assertOk()->viewData('page')['props'];
        $all = $this->get('/dashboard?workspace=all')->assertOk()->viewData('page')['props'];

        $this->assertSame(['••••2233'], collect($one['recentConversations']['data'])->pluck('contact')->all());
        $this->assertSame(1, $one['kpis']['data']['conversations']['value']);
        $this->assertSame(['••••2233'], collect($all['recentConversations']['data'])->pluck('contact')->all());
        $this->assertStringNotContainsString('Laura', json_encode([$one, $all]));
        $this->assertStringNotContainsString('573001112233', json_encode([$one, $all]));
    }

    public function test_the_audit_of_another_workspace_hides_the_ip_and_the_full_email_of_its_team_and_never_a_contact_name(): void
    {
        $actor = $this->member($this->client, 'admin');
        $this->actingAs($actor)->withSession(['workspace_id' => $this->client->id]);
        $this->post('/customers', ['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->put("/customers/{$customer->id}", ['first_name' => 'Laura Sofía', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasNoErrors();
        $this->assertNotNull(AuditLog::where('workspace_id', $this->client->id)->whereNotNull('ip_address')->first(), 'the owner Workspace keeps the IP');

        $this->signInDev('admin', true);
        $events = collect($this->get("/audit?workspace={$this->client->id}")->assertOk()->viewData('page')['props']['events']['data']);

        $this->assertTrue($events->isNotEmpty());
        $this->assertTrue($events->every(fn ($event) => $event['ip'] === null));
        $this->assertTrue($events->every(fn ($event) => $event['user']['email'] === Mask::email($actor->email)));
        $this->assertStringNotContainsString('Laura', json_encode($events));
        $this->assertStringNotContainsString($actor->email, json_encode($events));
    }

    public function test_legacy_audit_rows_that_hold_a_contact_name_are_not_shown(): void
    {
        AuditLog::create([
            'workspace_id' => $this->client->id, 'workspace_code' => $this->client->code, 'workspace_name' => $this->client->name,
            'user_id' => null, 'user_name' => 'Persona', 'user_email' => 'p@example.com', 'action' => 'taken', 'resource_type' => 'conversation', 'resource_id' => 7,
            'resource_label' => 'María García', 'description' => 'Tomó la conversación María García', 'changes' => [], 'ip_address' => null,
        ]);
        $this->contractModules($this->client, ['dashboard', 'audit']);
        $member = $this->member($this->client, 'admin');
        $this->actingAs($member)->withSession(['workspace_id' => $this->client->id]);

        $event = $this->get('/audit')->assertOk()->viewData('page')['props']['events']['data'][0];

        $this->assertSame(['#7', 'Tomó la conversación #7'], [$event['record'], $event['description']]);
    }

    public function test_reports_hide_the_full_email_of_the_team_of_another_workspace(): void
    {
        $this->contractModules($this->client, ['dashboard', 'reports', 'users_roles']);
        $staff = $this->member($this->client, 'admin');
        $this->signInDev('admin', true);
        config(['workspace.admin_code' => 'DESARROLLO_DEV']);
        Role::findByName('admin', 'web')->givePermissionTo('view-users');

        $props = $this->get("/reports?workspace={$this->client->id}")->assertOk()->viewData('page')['props'];
        $emails = collect($props['recentUsers'] ?? [])->pluck('email');

        $this->assertTrue($emails->isNotEmpty());
        $this->assertNotContains($staff->email, $emails->all());
        $this->assertContains(Mask::email($staff->email), $emails->all());
    }

    // --- customers, campaigns, bookings: isolation -----------------------------------------------------------------

    public function test_a_platform_administrator_never_reaches_the_customers_or_campaigns_of_a_client_workspace(): void
    {
        $theirs = $this->client->customers()->create(['first_name' => 'Ajena', 'phone' => '+573009998888', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'ok']);
        $campaign = $this->client->campaigns()->create(['name' => 'Ajena', 'body' => 'Hola', 'channel' => 'whatsapp', 'audience' => 'all']);
        $dev = $this->signInDev('admin', true);
        $this->contractModules($this->dev, ['customers', 'campaigns']);
        $this->dev->update(['plan_id' => null]);
        $this->assertContains('customers', app(ModuleAccess::class)->keys($this->dev));
        $this->assertNotNull($dev);

        // The parameter that other pages accept is ignored here: customers and campaigns only ever belong to the session's Workspace.
        $this->get("/customers?workspace={$this->client->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('customers.meta.total', 0));
        $this->get("/customers/{$theirs->id}")->assertNotFound();
        $this->put("/customers/{$theirs->id}", ['first_name' => 'X', 'phone' => '+573009998888', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertNotFound();
        $this->post("/customers/{$theirs->id}/anonymize")->assertNotFound();
        $this->get("/campaigns?workspace={$this->client->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('campaigns.meta.total', 0));
        $this->get("/campaigns/{$campaign->id}")->assertNotFound();
        $this->post("/campaigns/{$campaign->id}/prepare")->assertNotFound();
        $this->assertSame('Ajena', $theirs->fresh()->first_name);
    }

    public function test_conversations_bookings_and_campaigns_of_one_client_never_reach_another(): void
    {
        $me = $this->member($this->client, 'admin');
        $this->actingAs($me)->withSession(['workspace_id' => $this->client->id]);
        $mine = $this->client->customers()->create(['first_name' => 'Mía', 'phone' => '+573001110000', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'ok']);
        $this->client->customers()->first()->identities()->create(['workspace_id' => $this->client->id, 'channel' => 'whatsapp', 'external_id' => '573009998888']);
        $foreignConversation = $this->conversationOf($this->other, '573009998888', 'Ajena', 'mensaje del otro cliente');
        $this->other->customers()->create(['first_name' => 'Ajena', 'phone' => '+573009998888', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'ok']);

        // Same channel id in another Workspace: not linked to my customer.
        $this->contractModules($this->client, ['dashboard', 'conversations', 'customers', 'campaigns']);
        $this->get("/customers/{$mine->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('conversations', 0)->where('appointments', null));
        $this->get("/conversations?c={$foreignConversation->id}")->assertNotFound();

        // A campaign of mine over "all eligible customers" only ever addresses mine.
        $campaign = $this->client->campaigns()->create(['name' => 'Mía', 'body' => 'Hola', 'channel' => 'whatsapp', 'audience' => 'all']);
        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($this->client->customers()->pluck('id')->all(), $campaign->recipients()->pluck('customer_id')->all());
        $this->assertSame(0, $campaign->recipients()->whereIn('customer_id', $this->other->customers()->pluck('id'))->count());
    }

    // --- anonymization ---------------------------------------------------------------------------------------------

    public function test_anonymizing_a_customer_removes_their_personal_data_and_never_addresses_them_again(): void
    {
        $me = $this->member($this->client, 'admin');
        $this->actingAs($me)->withSession(['workspace_id' => $this->client->id]);
        $customer = $this->client->customers()->create(['first_name' => 'Laura', 'last_name' => 'Pérez', 'phone' => '+573001112233', 'email' => 'laura@example.com', 'notes' => 'nota privada', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'formulario']);
        $customer->identities()->create(['workspace_id' => $this->client->id, 'channel' => 'whatsapp', 'external_id' => '573001112233']);
        $customer->tags()->create(['workspace_id' => $this->client->id, 'tag' => 'vip']);

        $this->post("/customers/{$customer->id}/anonymize")->assertSessionHasNoErrors();

        $fresh = Customer::with(['identities', 'tags'])->find($customer->id);
        $this->assertTrue($fresh->isAnonymized());
        $this->assertSame(['Cliente anonimizado', null, null, null, null, null, 'revoked', false], [$fresh->first_name, $fresh->last_name, $fresh->phone, $fresh->email, $fresh->notes, $fresh->consent_note, $fresh->marketing_consent, $fresh->is_active]);
        $this->assertSame([null, null], [$fresh->phone_normalized, $fresh->email_normalized]);
        $this->assertCount(0, $fresh->identities);
        $this->assertCount(0, $fresh->tags);
        $this->assertSame('Pérez', 'Pérez'); // the conversation of that person is not touched by anonymizing the record
        $this->assertTrue(Conversation::where('id', $this->conversation->id)->exists());

        $this->put("/customers/{$customer->id}", ['first_name' => 'Otra vez', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasErrors('customer');
        $this->post("/customers/{$customer->id}/activate")->assertSessionHasErrors('customer');
        $this->post("/customers/{$customer->id}/anonymize")->assertSessionHasErrors('customer');

        // The number is free again: a new, different record can use it.
        $this->post('/customers', ['first_name' => 'Nueva', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'unknown'])->assertSessionHasNoErrors();

        $logs = AuditLog::where('resource_type', 'customer')->get();
        $this->assertTrue($logs->contains('action', 'anonymized'));
        $dump = json_encode($logs->map->only(['resource_label', 'description', 'changes']));
        foreach (['Laura', 'Pérez', 'laura@example.com', '3001112233', 'nota privada', 'formulario'] as $private) {
            $this->assertStringNotContainsString($private, $dump);
        }
    }

    public function test_an_anonymized_customer_is_excluded_from_every_campaign(): void
    {
        $me = $this->member($this->client, 'admin');
        $this->actingAs($me)->withSession(['workspace_id' => $this->client->id]);
        $customer = $this->client->customers()->create(['first_name' => 'Laura', 'phone' => '+573001112233', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'ok']);
        $ok = $this->client->customers()->create(['first_name' => 'Ok', 'phone' => '+573005556677', 'source' => 'manual', 'marketing_consent' => 'granted', 'consent_note' => 'ok']);
        $this->post("/customers/{$customer->id}/anonymize");
        $campaign = $this->client->campaigns()->create(['name' => 'X', 'body' => 'Hola', 'channel' => 'whatsapp', 'audience' => 'all']);

        $this->post("/campaigns/{$campaign->id}/prepare")->assertSessionHasNoErrors();

        $this->assertSame('excluded', $campaign->recipients()->where('customer_id', $customer->id)->value('status'));
        $this->assertSame('pending', $campaign->recipients()->where('customer_id', $ok->id)->value('status'));
    }

    // --- architecture: groups, dependencies, navigation ------------------------------------------------------------

    public function test_every_implemented_module_belongs_to_a_known_group_and_the_catalog_reports_it(): void
    {
        $groups = array_keys(config('modules.groups'));

        foreach (config('modules.implemented') as $key => $module) {
            $this->assertContains($module['group'], $groups, "module {$key} has a group");
            $this->assertSame($module['group'], app(ModuleAccess::class)->groupOf($key, $module['category']));
        }

        $this->assertSame('solutions', app(ModuleAccess::class)->groupOf('unknown_module', 'a_category_created_in_the_ui'));
        $this->assertEqualsCanonicalizing(['users_roles', 'audit', 'settings', 'integrations'], collect(config('modules.implemented'))->filter(fn ($m) => $m['group'] === 'platform')->keys()->all());
        $this->assertEqualsCanonicalizing(['assistants', 'conversations', 'customers', 'campaigns', 'reminders', 'dashboard', 'reports'], collect(config('modules.implemented'))->filter(fn ($m) => $m['group'] === 'shared')->keys()->all());

        $platform = $this->signInDev('plataforma', false);
        $this->assertNotNull($platform);
        $catalog = $this->get('/plans')->assertOk()->viewData('page')['props']['catalog'];
        $modules = collect($catalog)->flatMap(fn ($category) => $category['modules']);
        $this->assertSame(['key' => 'shared', 'name' => 'Capacidades compartidas', 'sort' => 20], $modules->firstWhere('key', 'customers')['group']);
        $this->assertSame('solutions', $modules->firstWhere('key', 'bookings')['group']['key']);
    }

    public function test_dependencies_only_name_implemented_modules_and_have_no_cycles(): void
    {
        $implemented = array_keys(config('modules.implemented'));
        $dependencies = config('modules.dependencies');

        foreach ($dependencies as $module => $needs) {
            $this->assertContains($module, $implemented);
            foreach ($needs as $need) {
                $this->assertContains($need, $implemented, "{$module} needs {$need}");
            }
        }

        $reaches = function (string $from, string $target, array $seen = []) use (&$reaches, $dependencies): bool {
            foreach ($dependencies[$from] ?? [] as $next) {
                if ($next === $target || (! in_array($next, $seen, true) && $reaches($next, $target, [...$seen, $next]))) {
                    return true;
                }
            }

            return false;
        };

        foreach (array_keys($dependencies) as $module) {
            $this->assertFalse($reaches($module, $module), "{$module} depends on itself");
        }
    }

    public function test_the_menu_only_mentions_real_modules_and_routes_and_hides_what_is_not_contracted(): void
    {
        $source = file_get_contents(base_path('resources/js/config/navigation.js'));
        preg_match_all("/route: '([a-z_.]+)'.*?(?:module: '([a-z_]+)')?(?: \\}|,\\s*\\})/", $source, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as [, $route]) {
            $this->assertTrue(\Route::has($route), "navigation route {$route} exists");
        }

        preg_match_all("/module: '([a-z_]+)'/", $source, $modules);
        foreach (array_unique($modules[1]) as $module) {
            $this->assertArrayHasKey($module, config('modules.implemented'), "navigation module {$module} is implemented");
        }

        // The menu is driven by the shared `modules` of the plan: a Workspace with only the dashboard is not offered the rest.
        $minimal = $this->bookingWorkspace('MINIMO', ['dashboard']);
        $user = $this->member($minimal, 'admin');
        $this->actingAs($user)->withSession(['workspace_id' => $minimal->id]);
        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('modules', ['dashboard'])->where('platform', false));
    }

    public function test_the_administrative_workspace_keeps_every_implemented_module_and_the_clients_keep_their_plan(): void
    {
        $access = app(ModuleAccess::class);

        $this->assertEqualsCanonicalizing(array_keys(config('modules.implemented')), $access->keys($this->dev->fresh()));
        $this->assertEqualsCanonicalizing(['dashboard', 'assistants', 'conversations', 'customers', 'campaigns'], $access->keys($this->client->fresh()));
        $this->assertNotContains('bookings', $access->keys($this->client->fresh()));
    }

    public function test_no_log_call_of_the_application_carries_contacts_message_text_or_secrets(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/Log::(?:info|warning|error|debug|notice|critical)\((.*)\);/', file_get_contents($file->getPathname()), $calls);

            foreach ($calls[1] as $call) {
                if (preg_match('/contact_|\'body|token|secret|password|credential|email|phone|->body|getMessage\(\)/i', $call)) {
                    $offenders[] = $file->getFilename().': '.$call;
                }
            }
        }

        $this->assertSame([], $offenders, 'a log call must carry ids and fixed codes only');
    }

    // --- masking helpers -------------------------------------------------------------------------------------------

    public function test_masks_keep_only_what_is_needed_to_tell_records_apart(): void
    {
        $this->assertSame('••••2233', Mask::identifier('573001112233'));
        $this->assertSame('••••', Mask::identifier('1234'));
        $this->assertNull(Mask::identifier(''));
        $this->assertSame('a•••@example.com', Mask::email('ana@example.com'));
        $this->assertNull(Mask::email('no-email'));
    }
}
