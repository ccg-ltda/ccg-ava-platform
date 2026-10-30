<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\AuditLog;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\SignupRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\PaymentConfirmation;
use App\Services\ModuleAccess;
use App\Services\OperationalAlert;
use App\Services\SignupService;
use App\Services\SubscriptionService;
use App\Services\WorkspaceLimits;
use App\Support\Nit;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The conditions a customer accepted are frozen on its subscription (editing the plan only reaches NEW contracts), a subscription whose conditions were never
 * recorded is shown as historical and incomplete (nothing is invented), and a free trial is granted by hand to ONE Workspace from the administration, with its
 * dates, reason and author audited; its expiry is surfaced, never enforced automatically (that policy is not approved). No payment is simulated: the payment of
 * a sign-up is "confirmed" only through the integration point the provider will use.
 */
class SubscriptionTermsAndTrialsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Plan $other;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $health = ModuleCategory::where('key', 'health')->firstOrFail();
        $health->update(['is_public' => true]);
        $this->plan = Plan::create(['name' => 'Salud base', 'module_category_id' => $health->id, 'currency' => 'COP', 'is_public' => true, 'price_monthly_cents' => 4990000, 'price_yearly_cents' => 49900000]);
        $this->plan->modules()->sync(Module::whereIn('key', ['dashboard', 'assistants', 'conversations'])->pluck('id'));
        PlanLimit::create(['plan_id' => $this->plan->id, 'metric' => 'users', 'value' => 5]);
        PlanLimit::create(['plan_id' => $this->plan->id, 'metric' => 'ai_executions', 'value' => 1000]);
        $this->other = Plan::create(['name' => 'Otro plan', 'module_category_id' => $health->id]);
        $this->other->modules()->sync(Module::whereIn('key', ['dashboard'])->pluck('id'));
    }

    private function platformAdmin(): User
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $user = User::factory()->create();
        Role::findOrCreate('plataforma', 'web');
        $dev->users()->attach($user->id, ['role' => 'plataforma']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        return $user;
    }

    private function client(string $code = 'CLIENTE'): Workspace
    {
        return Workspace::create(['organization_id' => Organization::create(['name' => "Org {$code}"])->id, 'code' => $code, 'name' => "Cliente {$code}"]);
    }

    private function modules(Workspace $workspace): array
    {
        $keys = app(ModuleAccess::class)->keys($workspace->fresh());
        sort($keys);

        return $keys;
    }

    private function nit(): string
    {
        return '900123456-'.Nit::checkDigit('900123456');
    }

    /** The public sign-up of the plan up to a paid, provisioned contract (the payment goes through the provider's integration point). */
    private function signUp(string $period = 'monthly', string $email = 'ana@empresa.co', string $code = 'clinica-central', string $nit = '900123456'): Workspace
    {
        auth()->logout(); // the sign-up is for guests
        $this->flushSession();
        $this->post('/registro/correo', ['email' => $email])->assertSessionHasNoErrors();
        $this->post('/registro/codigo', ['code' => Mail::sent(SignupCodeMail::class)->last()->code])->assertSessionHasNoErrors();
        $this->post('/registro/empresa', [
            'admin_name' => 'Ana Pérez', 'company_name' => 'Clínica '.$code, 'tax_id' => $nit.'-'.Nit::checkDigit($nit), 'phone' => '+57 300 111 2233',
            'workspace_code' => $code, 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ])->assertSessionHasNoErrors();
        $this->post('/registro/solucion', ['solution' => (string) $this->plan->module_category_id]);
        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => $period])->assertSessionHasNoErrors();
        $this->post('/registro/contratar', ['accepted' => array_keys(app(SignupService::class)->requiredDocuments())])->assertSessionHasNoErrors();
        $request = SignupRequest::where('email', $email)->firstOrFail();
        app(SignupService::class)->confirmPayment(new PaymentConfirmation($request->public_id, 'TX-'.$code, $request->price_cents, $request->currency));

        return Workspace::where('code', strtoupper($code))->firstOrFail();
    }

    // --- frozen conditions ---------------------------------------------------------------------------------------------

    public function test_a_signup_freezes_what_the_customer_accepted_and_editing_the_plan_never_changes_it(): void
    {
        $workspace = $this->signUp('yearly');
        $terms = Subscription::where('workspace_id', $workspace->id)->firstOrFail()->terms;

        $this->assertSame('signup', $terms['source']);
        $this->assertSame([49900000, 'COP', 'yearly'], [$terms['price']['amount_cents'], $terms['price']['currency'], $terms['price']['period']]);
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $terms['modules']);
        $this->assertSame([5, 1000], [$terms['limits']['users'], $terms['limits']['ai_executions']]);
        $this->assertSame($this->plan->id, $terms['plan']['id']);
        $before = $this->modules($workspace);
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $before);

        // The administration edits the plan: price, modules and limits.
        $this->plan->update(['price_monthly_cents' => 9990000, 'price_yearly_cents' => 99900000]);
        $this->plan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports', 'customers'])->pluck('id'));
        PlanLimit::where('plan_id', $this->plan->id)->where('metric', 'users')->update(['value' => 1]);
        PlanLimit::where('plan_id', $this->plan->id)->where('metric', 'ai_executions')->delete();

        $frozen = Subscription::where('workspace_id', $workspace->id)->firstOrFail()->terms;
        $this->assertSame([49900000, ['assistants', 'conversations', 'dashboard'], 5, 1000], [$frozen['price']['amount_cents'], $frozen['modules'], $frozen['limits']['users'], $frozen['limits']['ai_executions']]);
        $this->assertSame($before, $this->modules($workspace), 'the modules the customer contracted are the ones it has');
        $limits = app(WorkspaceLimits::class);
        $this->assertSame([5, 'contract', 1], [$limits->effective($workspace->fresh(), 'users')['value'], $limits->effective($workspace->fresh(), 'users')['source'], $limits->effective($workspace->fresh(), 'users')['planNow']]);

        // A NEW contract receives the conditions of the catalog today.
        $second = $this->signUp('monthly', 'beto@empresa.co', 'hospital-norte', '800200300');
        $new = Subscription::where('workspace_id', $second->id)->firstOrFail()->terms;
        $this->assertSame([9990000, 'monthly', ['customers', 'dashboard', 'reports'], 1], [$new['price']['amount_cents'], $new['price']['period'], $new['modules'], $new['limits']['users']]);
        $this->assertNull($new['limits']['ai_executions'], 'the plan sets none today: none is claimed');
        $this->assertSame(['customers', 'dashboard', 'reports'], $this->modules($second));
        $this->assertSame($frozen, Subscription::where('workspace_id', $workspace->id)->firstOrFail()->terms, 'the first customer is untouched');
    }

    public function test_the_modules_accepted_are_kept_even_if_the_plan_changed_between_acceptance_and_payment(): void
    {
        $this->flushSession();
        $this->post('/registro/correo', ['email' => 'ana@empresa.co']);
        $this->post('/registro/codigo', ['code' => Mail::sent(SignupCodeMail::class)->last()->code]);
        $this->post('/registro/empresa', ['admin_name' => 'Ana Pérez', 'company_name' => 'Clínica', 'tax_id' => $this->nit(), 'phone' => '+57 300 111 2233', 'workspace_code' => 'clinica-x', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123']);
        $this->post('/registro/solucion', ['solution' => (string) $this->plan->module_category_id]);
        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'monthly']);
        $this->post('/registro/contratar', ['accepted' => array_keys(app(SignupService::class)->requiredDocuments())])->assertSessionHasNoErrors();

        $this->plan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports', 'customers', 'campaigns'])->pluck('id')); // changed after accepting
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->andReturnNull();
        $request = SignupRequest::firstOrFail();
        app(SignupService::class)->confirmPayment(new PaymentConfirmation($request->public_id, 'TX-9', $request->price_cents, $request->currency));

        $workspace = Workspace::where('code', 'CLINICA-X')->firstOrFail();
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace), 'what the customer accepted, not what the plan became');
    }

    public function test_a_historical_subscription_is_flagged_not_invented_and_follows_its_plan_until_an_administrator_freezes_it(): void
    {
        $workspace = $this->client();
        $workspace->update(['plan_id' => $this->plan->id]);
        Subscription::create(['workspace_id' => $workspace->id, 'plan_id' => $this->plan->id, 'status' => Subscription::ACTIVE, 'payment_status' => 'confirmed', 'payment_reference' => 'TX-OLD', 'activated_at' => now()->subYear()]);
        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();

        $this->assertFalse($subscription->hasFrozenTerms());
        $this->assertNull($subscription->terms);
        $this->assertSame('plan', app(WorkspaceLimits::class)->effective($workspace->fresh(), 'users')['source'], 'no recorded conditions: the plan, read live, and the page says so');

        $admin = $this->platformAdmin();
        $page = $this->get("/workspaces/{$workspace->id}/modules")->viewData('page')['props']['data']['subscription'];
        $this->assertNull($page['terms']);
        $this->assertTrue($page['termsReady']);

        $this->post("/workspaces/{$workspace->id}/terms/freeze")->assertSessionHasNoErrors();
        $frozen = $subscription->fresh();
        $this->assertSame(['frozen_current', null], [$frozen->terms_source, $frozen->terms['price']]);
        $this->assertSame('contract', app(WorkspaceLimits::class)->effective($workspace->fresh(), 'users')['source']);
        $this->assertTrue(AuditLog::where('workspace_id', $workspace->id)->where('resource_type', 'subscription')->where('user_id', $admin->id)->exists());

        $this->post("/workspaces/{$workspace->id}/terms/freeze")->assertSessionHasErrors('terms'); // already frozen
    }

    public function test_changing_the_plan_of_a_workspace_with_a_subscription_freezes_the_new_conditions(): void
    {
        $workspace = $this->signUp();
        $this->platformAdmin();

        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->other->id])->assertSessionHasNoErrors();

        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame([$this->other->id, 'manual', null, ['dashboard']], [$subscription->plan_id, $subscription->terms_source, $subscription->terms['price'], $subscription->terms['modules']]);
        $this->assertSame(['dashboard'], $this->modules($workspace));
    }

    // --- free trials, by hand, one Workspace -------------------------------------------------------------------------------

    private function grant(Workspace $workspace, array $override = [])
    {
        return $this->post("/workspaces/{$workspace->id}/trial", $override + ['plan_id' => $this->plan->id, 'ends_at' => now()->addDays(14)->toDateString(), 'reason' => 'Piloto acordado con ventas']);
    }

    public function test_an_administrator_grants_a_trial_to_one_workspace_and_everything_is_recorded(): void
    {
        $workspace = $this->client();
        $bystander = $this->client('OTRO');
        $bystanderPlan = $bystander->plan_id;
        $admin = $this->platformAdmin();

        $this->grant($workspace)->assertSessionHasNoErrors();

        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(Subscription::TRIALING, $subscription->status);
        $this->assertSame([$admin->id, 'Piloto acordado con ventas', 'trial', null], [$subscription->trial_granted_by, $subscription->trial_reason, $subscription->terms_source, $subscription->terms['price']]);
        $this->assertTrue($subscription->trial_ends_at->isFuture());
        $this->assertSame($this->plan->id, $workspace->fresh()->plan_id);
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace), 'the trial gives the plan modules');
        $this->assertSame(5, app(WorkspaceLimits::class)->effective($workspace->fresh(), 'users')['value']);

        // Nothing else moved: not the plan, not another Workspace, and no subscription row was invented for it.
        $this->assertNull(Subscription::where('workspace_id', $bystander->id)->first());
        $this->assertSame($bystanderPlan, $bystander->fresh()->plan_id);
        $this->assertSame(1, Subscription::count());
        $this->assertTrue(AuditLog::where('workspace_id', $workspace->id)->where('resource_type', 'subscription')->where('user_id', $admin->id)->get()
            ->contains(fn ($log) => str_contains(json_encode($log->changes), 'Piloto acordado con ventas')));

        $page = $this->get("/workspaces/{$workspace->id}/modules")->viewData('page')['props'];
        $this->assertSame([true, false, $admin->name], [$page['data']['subscription']['trial']['active'], $page['data']['subscription']['trial']['expired'], $page['data']['subscription']['trial']['by']]);
        $this->assertNotEmpty($page['history']);
    }

    public function test_a_trial_is_validated_on_the_server_and_never_duplicated_or_given_to_a_live_subscription(): void
    {
        $workspace = $this->client();
        $this->platformAdmin();

        $this->grant($workspace, ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->grant($workspace, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->grant($workspace, ['ends_at' => now()->subDay()->toDateString()])->assertSessionHasErrors('ends_at');
        $this->grant($workspace, ['ends_at' => 'mañana'])->assertSessionHasErrors('ends_at');
        $this->grant($workspace, ['ends_at' => now()->addDays(900)->toDateString()])->assertSessionHasErrors('ends_at');
        $this->grant($workspace, ['plan_id' => 99999])->assertSessionHasErrors('plan_id');
        $this->assertSame(0, Subscription::count());

        $this->other->update(['is_active' => false]);
        $this->grant($workspace, ['plan_id' => $this->other->id])->assertSessionHasErrors('plan_id'); // an inactive plan can not be assigned
        $this->assertSame(0, Subscription::count());

        $this->grant($workspace)->assertSessionHasNoErrors();
        $this->grant($workspace)->assertSessionHasErrors('trial'); // duplicate
        $this->assertSame(1, Subscription::count());

        // A paying customer is not given a trial.
        $paying = $this->signUp();
        $this->platformAdmin();
        $this->grant($paying)->assertSessionHasErrors('trial');
        $this->assertSame(Subscription::ACTIVE, Subscription::where('workspace_id', $paying->id)->value('status'));

        // The internal Workspace has neither subscription nor trial.
        $dev = Workspace::where('code', config('workspace.admin_code'))->firstOrFail();
        $this->grant($dev)->assertSessionHasErrors('workspace_id');
    }

    public function test_only_the_platform_administration_can_manage_a_trial(): void
    {
        $workspace = $this->client();
        $client = User::factory()->create();
        $workspace->users()->attach($client->id, ['role' => 'admin']);
        $this->actingAs($client)->withSession(['workspace_id' => $workspace->id]);

        $this->grant($workspace)->assertForbidden();
        $this->put("/workspaces/{$workspace->id}/trial", ['ends_at' => now()->addDay()->toDateString(), 'reason' => 'Me la alargo'])->assertForbidden();
        $this->post("/workspaces/{$workspace->id}/trial/cancel", ['reason' => 'Quiero cancelar'])->assertForbidden();
        $this->post("/workspaces/{$workspace->id}/terms/freeze")->assertForbidden();
        $this->assertSame(0, Subscription::count());
    }

    public function test_a_trial_can_be_extended_and_cancelled_with_a_reason_and_cancelling_keeps_the_data(): void
    {
        $workspace = $this->client();
        $workspace->users()->attach(User::factory()->create()->id, ['role' => 'admin']);
        $this->platformAdmin();
        $this->grant($workspace);
        $end = now()->addDays(30)->toDateString();

        $this->put("/workspaces/{$workspace->id}/trial", ['ends_at' => $end])->assertSessionHasErrors('reason');
        $this->put("/workspaces/{$workspace->id}/trial", ['ends_at' => $end, 'reason' => 'Se amplía por la migración'])->assertSessionHasNoErrors();
        $this->assertSame($end, Subscription::firstOrFail()->trial_ends_at->toDateString());
        $this->put("/workspaces/{$workspace->id}/trial", ['ends_at' => now()->subDay()->toDateString(), 'reason' => 'Fecha pasada'])->assertSessionHasErrors('ends_at');

        $this->post("/workspaces/{$workspace->id}/trial/cancel", [])->assertSessionHasErrors('reason');
        $this->post("/workspaces/{$workspace->id}/trial/cancel", ['reason' => 'El cliente no continúa'])->assertSessionHasNoErrors();
        $this->assertSame(Subscription::CANCELLED, Subscription::firstOrFail()->status);
        $this->assertSame([], $this->modules($workspace), 'a cancelled subscription withdraws access');
        $this->assertSame(1, $workspace->users()->count(), 'the data stays');
        $this->post("/workspaces/{$workspace->id}/trial/cancel", ['reason' => 'Otra vez'])->assertSessionHasErrors('trial');
    }

    public function test_an_expired_trial_restricts_access_at_once_keeps_every_record_and_the_command_is_idempotent(): void
    {
        $workspace = $this->client();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => 'admin']);
        $this->platformAdmin();
        $this->grant($workspace, ['ends_at' => now()->addDays(2)->toDateString()]);
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace));
        $organizations = Organization::count();

        $this->travel(5)->days();
        $this->renewAbsoluteSession();

        // The server decides on every request: no waiting for the scheduled command.
        $subscription = Subscription::firstOrFail();
        $this->assertTrue($subscription->trialExpired());
        $this->assertTrue($subscription->withdrawsAccess());
        $this->assertSame([], $this->modules($workspace), 'no paid modules, so no AI either');

        // The command makes the state explicit and audited; running it again changes and audits nothing more.
        $this->assertSame(0, Artisan::call('subscriptions:expire-trials'));
        $expired = Subscription::firstOrFail();
        $this->assertSame(Subscription::EXPIRED, $expired->status);
        $this->assertSame($expired->trial_ends_at->toDateString(), $expired->ends_at->toDateString());
        $audits = AuditLog::where('workspace_id', $workspace->id)->where('resource_type', 'subscription')->count();
        Artisan::call('subscriptions:expire-trials');
        Artisan::call('subscriptions:expire-trials');
        $this->assertSame($audits, AuditLog::where('workspace_id', $workspace->id)->where('resource_type', 'subscription')->count());
        $this->assertSame(Subscription::EXPIRED, Subscription::firstOrFail()->status);

        // Nothing was deleted or altered besides the subscription state.
        $this->assertSame([1, $organizations, $this->plan->id], [$workspace->users()->count(), Organization::count(), $workspace->fresh()->plan_id]);
        $this->assertTrue(User::whereKey($member->id)->exists());
        $this->assertTrue(Workspace::whereKey($workspace->id)->where('is_active', true)->exists());

        // The person is told what happened and that the data is safe, instead of meeting an error; a paid module answers 403.
        $this->actingAs($member)->withSession(['workspace_id' => $workspace->id]);
        $this->get('/dashboard')->assertRedirect(route('workspace.empty'));
        $this->get('/sin-modulos')->assertOk()->assertInertia(fn ($page) => $page->where('reason', 'trial_expired')->where('trialEndedAt', fn ($value) => filled($value)));
        $this->get('/conversations')->assertForbidden();
    }

    public function test_a_confirmed_payment_reopens_an_expired_trial_with_the_same_workspace_and_data(): void
    {
        $workspace = $this->client();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => 'admin']);
        $this->platformAdmin();
        $this->grant($workspace, ['ends_at' => now()->addDays(2)->toDateString()]);
        $this->travel(5)->days();
        $this->renewAbsoluteSession();
        Artisan::call('subscriptions:expire-trials');
        $this->assertSame([], $this->modules($workspace));
        $workspaces = Workspace::count();

        // The integration point of the payment provider (the only one that may confirm a payment): a reference is required and nothing is created twice.
        $subscription = Subscription::firstOrFail();
        $this->assertThrows(fn () => app(SubscriptionService::class)->confirmPayment($subscription, ' '), ValidationException::class);
        $this->assertSame([], $this->modules($workspace), 'a blank confirmation reopens nothing');

        app(SubscriptionService::class)->confirmPayment($subscription, 'TX-REOPEN');
        $reopened = Subscription::firstOrFail();
        $this->assertSame([Subscription::ACTIVE, null], [$reopened->status, $reopened->ends_at]);
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace));
        $this->assertSame([$workspaces, 1, 1], [Workspace::count(), $workspace->users()->count(), Subscription::count()], 'same Workspace, same data, no duplicate');

        app(SubscriptionService::class)->confirmPayment($reopened, 'TX-REOPEN'); // a repeated notification changes nothing
        $this->assertSame(1, Subscription::count());
    }

    public function test_the_browser_can_not_reopen_an_expired_trial_by_itself(): void
    {
        $workspace = $this->client();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => 'admin']);
        $this->platformAdmin();
        $this->grant($workspace, ['ends_at' => now()->addDays(2)->toDateString()]);
        $this->travel(5)->days();
        $this->renewAbsoluteSession();
        Artisan::call('subscriptions:expire-trials');

        $this->actingAs($member)->withSession(['workspace_id' => $workspace->id]);
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->plan->id, 'payment_status' => 'confirmed'])->assertForbidden();
        $this->post("/workspaces/{$workspace->id}/trial", ['plan_id' => $this->plan->id, 'ends_at' => now()->addDays(9)->toDateString(), 'reason' => 'Me la concedo yo'])->assertForbidden();
        $this->assertSame(Subscription::EXPIRED, Subscription::firstOrFail()->status);
        $this->assertSame([], $this->modules($workspace));
    }

    public function test_a_signup_never_receives_a_trial_by_itself(): void
    {
        $workspace = $this->signUp();

        $this->assertSame(Subscription::ACTIVE, Subscription::where('workspace_id', $workspace->id)->value('status'));
        $this->assertNull(Subscription::where('workspace_id', $workspace->id)->value('trial_ends_at'));
    }

    // --- a database that is not ready ----------------------------------------------------------------------------------------

    public function test_without_the_new_columns_trials_are_refused_and_modules_follow_the_plan(): void
    {
        Schema::table('subscriptions', fn ($table) => $table->dropConstrainedForeignId('trial_granted_by'));
        Schema::table('subscriptions', fn ($table) => $table->dropColumn(['terms', 'terms_source', 'terms_captured_at', 'trial_starts_at', 'trial_ends_at', 'trial_reason']));
        Schema::table('signup_requests', fn ($table) => $table->dropColumn('limits_snapshot'));
        $workspace = $this->client();
        $workspace->update(['plan_id' => $this->plan->id]);
        $this->platformAdmin();

        $this->assertFalse(Subscription::termsReady());
        $this->grant($workspace)->assertSessionHasErrors('trial');
        $this->assertSame(0, Subscription::count());
        $this->assertSame(['assistants', 'conversations', 'dashboard'], $this->modules($workspace));
        $this->assertSame(0, Artisan::call('subscriptions:expire-trials'));

        $page = $this->get("/workspaces/{$workspace->id}/modules")->assertOk()->viewData('page')['props'];
        $this->assertNull($page['data']['subscription']);

        // The sign-up still provisions: nothing is frozen yet, and nothing breaks.
        $signed = $this->signUp('monthly', 'ana@empresa.co', 'sin-columnas', '900555666');
        $this->assertSame(Subscription::ACTIVE, Subscription::where('workspace_id', $signed->id)->value('status'));
    }

    // --- D-CON1: a price agreed outside the public sign-up ---------------------------------------------------------------

    private function historical(string $code = 'HISTORICO'): Workspace
    {
        $workspace = $this->client($code);
        $workspace->update(['plan_id' => $this->plan->id]);
        Subscription::create(['workspace_id' => $workspace->id, 'plan_id' => $this->plan->id, 'status' => Subscription::ACTIVE, 'payment_status' => 'confirmed', 'payment_reference' => 'TX-OLD-'.$code, 'activated_at' => now()->subYear()]);

        return $workspace;
    }

    private function agreement(array $over = []): array
    {
        return array_merge(['amount' => '1500000.50', 'currency' => 'COP', 'period' => 'yearly', 'origin' => 'contract', 'reason' => 'Contrato firmado con el cliente'], $over);
    }

    public function test_an_agreed_price_is_recorded_over_frozen_terms_with_history_and_audit(): void
    {
        $workspace = $this->historical();
        $admin = $this->platformAdmin();
        $this->post("/workspaces/{$workspace->id}/terms/freeze")->assertSessionHasNoErrors();
        $modules = $this->modules($workspace);

        $this->post("/workspaces/{$workspace->id}/terms/agreement", $this->agreement())->assertSessionHasNoErrors();
        $this->post("/workspaces/{$workspace->id}/terms/agreement", $this->agreement(['amount' => '99000', 'period' => 'monthly', 'origin' => 'proposal', 'reason' => 'Descuento aprobado']))->assertSessionHasNoErrors();

        $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('agreement', $subscription->terms_source);
        $this->assertSame([9900000, 'COP', 'monthly'], [$subscription->terms['price']['amount_cents'], $subscription->terms['price']['currency'], $subscription->terms['price']['period']]);
        $this->assertCount(2, $subscription->terms['agreements']);
        $this->assertSame(150000050, $subscription->terms['agreements'][0]['price']['amount_cents']);
        $this->assertSame(150000050, $subscription->terms['agreements'][1]['replaces']['amount_cents'], 'the earlier agreement stays in the history');
        $this->assertSame([$admin->id, 'proposal', 'Descuento aprobado'], [$subscription->terms['agreements'][1]['agreed_by'], $subscription->terms['agreements'][1]['origin'], $subscription->terms['agreements'][1]['reason']]);
        $this->assertSame($modules, $this->modules($workspace), 'an agreed price never touches the modules');
        $this->assertTrue(AuditLog::where('workspace_id', $workspace->id)->where('resource_type', 'subscription')->where('user_id', $admin->id)->get()->contains(fn ($log) => str_contains(json_encode($log->changes), 'Precio acordado')));
    }

    public function test_an_agreement_is_validated_on_the_server_and_never_freezes_a_historical_subscription_silently(): void
    {
        $workspace = $this->historical();
        $this->platformAdmin();

        // Historical: refused until an administrator freezes the conditions on purpose; nothing is invented or changed.
        $this->post("/workspaces/{$workspace->id}/terms/agreement", $this->agreement())->assertSessionHasErrors('agreement');
        $this->assertNull(Subscription::where('workspace_id', $workspace->id)->firstOrFail()->terms);

        $this->post("/workspaces/{$workspace->id}/terms/freeze");
        $before = Subscription::where('workspace_id', $workspace->id)->firstOrFail()->terms;

        foreach ([['amount' => '0'], ['amount' => '-5'], ['amount' => 'abc'], ['currency' => 'pesos'], ['period' => 'weekly'], ['origin' => 'rumor'], ['reason' => 'no']] as $bad) {
            $this->post("/workspaces/{$workspace->id}/terms/agreement", $this->agreement($bad))->assertSessionHasErrors();
        }

        $this->assertSame($before, Subscription::where('workspace_id', $workspace->id)->firstOrFail()->terms);
    }

    public function test_only_the_platform_administration_can_record_an_agreement_and_never_in_another_workspace(): void
    {
        $workspace = $this->historical();
        $other = $this->historical('OTRO');
        $this->platformAdmin();
        $this->post("/workspaces/{$workspace->id}/terms/freeze");
        $this->post("/workspaces/{$other->id}/terms/freeze");
        $this->post("/workspaces/{$workspace->id}/terms/agreement", $this->agreement())->assertSessionHasNoErrors();

        $this->assertNull(Subscription::where('workspace_id', $other->id)->firstOrFail()->terms['price'], 'another Workspace is untouched');

        $client = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $other->users()->attach($client->id, ['role' => 'admin']);
        $this->actingAs($client)->withSession(['workspace_id' => $other->id]);
        $this->post("/workspaces/{$workspace->id}/terms/agreement", $this->agreement())->assertForbidden();
        $this->post("/workspaces/{$other->id}/terms/agreement", $this->agreement())->assertForbidden();
    }

    public function test_historical_subscriptions_keep_access_and_are_never_frozen_in_bulk(): void
    {
        $a = $this->historical('HIST_A');
        $b = $this->historical('HIST_B');
        $before = $this->modules($a);
        $this->assertNotEmpty($before);

        Artisan::call('subscriptions:expire-trials');
        $this->plan->update(['price_monthly_cents' => 1]);

        foreach ([$a, $b] as $workspace) {
            $subscription = Subscription::where('workspace_id', $workspace->id)->firstOrFail();
            $this->assertSame([null, null, Subscription::ACTIVE], [$subscription->terms, $subscription->terms_source, $subscription->status]);
        }

        $this->assertSame($before, $this->modules($a), 'a historical subscription keeps using its current plan');
    }
}
