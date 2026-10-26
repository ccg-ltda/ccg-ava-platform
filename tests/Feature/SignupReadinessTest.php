<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SignupRequest;
use App\Models\SignupVerification;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\PaymentConfirmation;
use App\Services\ModuleAccess;
use App\Services\OperationalAlert;
use App\Services\SignupCatalog;
use App\Services\SignupService;
use App\Support\Nit;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Operational readiness of the public sign-up: an empty catalog is stated and never collects data, a plan is offered only while it is complete, the
 * administration publishes and unpublishes offers through its own endpoints with their own permissions, a change of the offer while someone is signing up
 * never charges silently different conditions, and a payment already recorded whose provisioning failed can be found and completed (reconciliation).
 * Mail is faked and no payment exists: the payment is "confirmed" only through the integration point the provider will use.
 */
class SignupReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ana@empresa.co';

    private ModuleCategory $health;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $this->health = ModuleCategory::where('key', 'health')->firstOrFail();
        $this->health->update(['is_public' => true]); // a solution is offered publicly only when an administrator made it public
    }

    /** @param  list<string>  $modules */
    private function plan(string $name, array $modules = ['dashboard', 'reports'], array $attributes = []): Plan
    {
        $plan = Plan::create($attributes + [
            'name' => $name, 'module_category_id' => $this->health->id, 'price_monthly_cents' => 4990000, 'currency' => 'COP', 'is_public' => true,
        ]);
        $plan->modules()->sync(Module::whereIn('key', $modules)->pluck('id'));

        return $plan->fresh(); // as read from the database, with its defaults
    }

    private function service(): SignupService
    {
        return app(SignupService::class);
    }

    private function nit(string $body = '900123456'): string
    {
        return $body.'-'.Nit::checkDigit($body);
    }

    private function verified(): SignupRequest
    {
        $this->post('/registro/correo', ['email' => self::EMAIL])->assertSessionHasNoErrors();
        $code = Mail::sent(SignupCodeMail::class)->last()->code;
        $this->post('/registro/codigo', ['code' => $code])->assertSessionHasNoErrors();

        return SignupRequest::where('email', self::EMAIL)->latest('id')->firstOrFail();
    }

    private function toSummary(Plan $plan): SignupRequest
    {
        $this->verified();
        $this->post('/registro/empresa', [
            'admin_name' => 'Ana Pérez', 'company_name' => 'Clínica Central', 'tax_id' => $this->nit(), 'phone' => '+57 300 111 2233',
            'workspace_code' => 'clinica-central', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ])->assertSessionHasNoErrors();
        $this->post('/registro/solucion', ['solution' => (string) $plan->module_category_id])->assertSessionHasNoErrors();
        $this->post('/registro/plan', ['plan_id' => $plan->id])->assertSessionHasNoErrors();

        return SignupRequest::where('email', self::EMAIL)->latest('id')->firstOrFail();
    }

    private function accepted(): array
    {
        return array_keys($this->service()->requiredDocuments());
    }

    private function contract(Plan $plan): SignupRequest
    {
        $request = $this->toSummary($plan);
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasNoErrors();

        return $request->fresh();
    }

    private function pay(SignupRequest $request, string $tx = 'TX-1'): SignupRequest
    {
        return $this->service()->confirmPayment(new PaymentConfirmation($request->public_id, $tx, $request->price_cents, $request->currency));
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

    // --- empty and incomplete catalog -------------------------------------------------------------------------------

    public function test_an_empty_catalog_is_stated_from_the_first_step_and_no_code_is_mailed_for_nothing(): void
    {
        $this->assertFalse(app(SignupCatalog::class)->hasOffers());

        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page->where('step', 'email')->where('offersAvailable', false)->where('solutions', []));
        $this->post('/registro/correo', ['email' => self::EMAIL])->assertSessionHasErrors('email');
        $this->assertStringContainsString('no hay ofertas', session('errors')->first('email'));

        Mail::assertNothingSent();
        $this->assertSame(0, SignupVerification::count());
        $this->assertSame(0, SignupRequest::count());
        $this->assertStringContainsString('!offersAvailable', file_get_contents(base_path('resources/js/Pages/Signup/Wizard.jsx')));
    }

    public function test_a_plan_is_offered_only_while_it_is_public_active_and_complete_and_the_admin_sees_why_not(): void
    {
        $ok = $this->plan('Completo');
        $quote = $this->plan('A medida', ['dashboard'], ['module_category_id' => null, 'price_monthly_cents' => null, 'currency' => null, 'requires_quote' => true]);
        $private = $this->plan('Privado', ['dashboard'], ['is_public' => false]);
        $noPrice = $this->plan('Sin precio', ['dashboard'], ['price_monthly_cents' => null]);
        $noCurrency = $this->plan('Sin moneda', ['dashboard'], ['currency' => null]);
        $noSolution = $this->plan('Sin solución', ['dashboard'], ['module_category_id' => null]);
        $inactive = $this->plan('Apagado', ['dashboard']);
        $inactive->update(['is_active' => false]);

        $this->assertSame('online', $ok->offerState()['state']);
        $this->assertSame('quote', $quote->offerState()['state']);
        $this->assertSame('private', $private->offerState()['state']);
        foreach ([$noPrice, $noCurrency, $noSolution, $inactive->fresh()] as $plan) {
            $this->assertSame('incomplete', $plan->offerState()['state'], $plan->name);
            $this->assertNotEmpty($plan->offerState()['issues']);
        }

        $catalog = app(SignupCatalog::class);
        $this->assertTrue($catalog->hasOffers());
        $this->assertSame(['Completo'], array_column($catalog->plans((string) $this->health->id), 'name'));
        $this->assertSame(['A medida'], array_column($catalog->plans('custom'), 'name'));

        // A solution that is switched off takes its plans out of the offer.
        $this->health->update(['is_active' => false]);
        $this->assertSame('incomplete', $ok->fresh()->offerState()['state']);
        $this->assertSame([], $catalog->plans((string) $this->health->id));
        $this->assertSame(['custom'], array_column($catalog->solutions(), 'key'));

        // The administration shows the same state and the reasons.
        $this->platformAdmin();
        $offers = collect($this->get('/plans')->viewData('page')['props']['plans'])->mapWithKeys(fn ($plan) => [$plan['name'] => $plan['offer']]);
        $this->assertSame('incomplete', $offers['Sin precio']['state']);
        $this->assertContains('Falta la moneda o al menos un precio (mensual o anual).', $offers['Sin precio']['issues']);
        $this->assertContains('Falta la solución.', $offers['Sin solución']['issues']);
        $this->assertSame('quote', $offers['A medida']['state']);
        $this->assertSame('private', $offers['Privado']['state']);
    }

    public function test_a_failing_mail_server_gives_a_controlled_error_voids_the_code_and_leaks_nothing(): void
    {
        $this->plan('Starter');
        $captured = [];
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->andReturnUsing(function (string $event, array $context = []) use (&$captured) {
            $captured[] = [$event, $context];
        });
        $mailer = \Mockery::mock(MailManager::class);
        $mailer->shouldReceive('to')->andThrow(new \RuntimeException('smtp.secret-host rejected ana@empresa.co with password hunter2'));
        Mail::swap($mailer);

        $response = $this->post('/registro/correo', ['email' => self::EMAIL]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('No pudimos enviar el código', session('errors')->first('email'));
        $this->assertStringNotContainsString('hunter2', $response->getContent().json_encode(session('errors')->all()));
        $this->assertNotNull(SignupVerification::firstOrFail()->consumed_at, 'a code that was never delivered cannot be used');
        $this->assertSame('signup_code_mail_failed', $captured[0][0]);
        $this->assertStringNotContainsString(self::EMAIL, json_encode($captured));
        $this->assertStringNotContainsString('hunter2', json_encode($captured));
        $this->assertSame(0, SignupRequest::count());
    }

    // --- the administration publishes and unpublishes through the interface -----------------------------------------

    public function test_an_authorized_administrator_publishes_and_unpublishes_an_offer_and_nobody_else_can(): void
    {
        $plan = Plan::create(['name' => 'Salud base']);
        $plan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports'])->pluck('id'));
        $offer = ['name' => 'Salud base', 'solution_ids' => [$this->health->id], 'price_monthly' => '49900', 'currency' => 'COP', 'is_public' => true, 'requires_quote' => false];

        // A client's administrator, even with every client permission, and a role without plan permissions are refused.
        $client = Workspace::create(['organization_id' => Organization::create(['name' => 'Cliente'])->id, 'code' => 'CLIENTE_X', 'name' => 'Cliente']);
        $adminClient = User::factory()->create();
        $client->users()->attach($adminClient->id, ['role' => 'admin']);
        $this->actingAs($adminClient)->withSession(['workspace_id' => $client->id])->put("/plans/{$plan->id}", $offer)->assertForbidden();
        $dev = Workspace::create(['organization_id' => $client->organization_id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $supervisor = User::factory()->create();
        $dev->users()->attach($supervisor->id, ['role' => 'supervisor']);
        $this->actingAs($supervisor)->withSession(['workspace_id' => $dev->id])->put("/plans/{$plan->id}", $offer)->assertForbidden();
        $this->assertFalse($plan->fresh()->is_public);

        // The authorized administrator publishes: the plan appears in the catalog with the configured price and no code involved.
        $this->platformAdmin();
        $this->put("/plans/{$plan->id}", $offer)->assertSessionHasNoErrors();
        $this->assertSame('online', $plan->fresh()->offerState()['state']);
        $this->assertTrue(app(SignupCatalog::class)->hasOffers());
        $this->assertSame(49900, app(SignupCatalog::class)->plans((string) $this->health->id)[0]['prices'][0]['amount']);

        // The server refuses an incomplete publication, whatever the interface sent.
        $this->put("/plans/{$plan->id}", array_merge($offer, ['price_monthly' => '']))->assertSessionHasErrors('price_monthly');
        $this->put("/plans/{$plan->id}", ['name' => 'Salud base', 'is_public' => true, 'currency' => 'COP', 'price_monthly' => '10', 'solution_ids' => []])->assertSessionHasErrors('solution_ids');
        $this->put("/plans/{$plan->id}", array_merge($offer, ['currency' => 'XXX']))->assertSessionHasErrors('currency');
        $this->put("/plans/{$plan->id}", array_merge($offer, ['price_monthly' => '-5']))->assertSessionHasErrors('price_monthly');
        $this->assertSame(4990000, $plan->fresh()->price_monthly_cents, 'a refused save changes nothing');

        // Unpublishing takes it out of the offer.
        $this->put("/plans/{$plan->id}", ['name' => 'Salud base', 'is_public' => false])->assertSessionHasNoErrors();
        $this->assertFalse(app(SignupCatalog::class)->hasOffers());
        $this->assertSame(4990000, $plan->fresh()->price_monthly_cents, 'the configured price is kept for when it is published again');
    }

    public function test_only_commercial_solutions_can_be_chosen_not_the_groups_of_shared_capabilities(): void
    {
        $shared = ModuleCategory::where('key', 'shared')->firstOrFail();
        $communication = ModuleCategory::where('key', 'communication')->firstOrFail();
        $custom = ModuleCategory::create(['key' => 'nueva', 'name' => 'Nueva solución', 'sort_order' => 90]); // created in Planes: no group in code

        $this->assertTrue($this->health->isSolution());
        $this->assertTrue($custom->isSolution());
        $this->assertFalse($shared->isSolution());
        $this->assertFalse($communication->isSolution());

        $this->platformAdmin();
        $options = collect($this->get('/plans')->viewData('page')['props']['commercial']['solutions'])->pluck('label');
        $this->assertTrue($options->contains('Salud') && $options->contains('Nueva solución'));
        $this->assertFalse($options->contains($shared->name) || $options->contains($communication->name));

        $plan = Plan::create(['name' => 'Mal clasificado']);
        $offer = ['name' => 'Mal clasificado', 'price_monthly' => '100', 'currency' => 'COP', 'is_public' => true];
        $this->put("/plans/{$plan->id}", array_merge($offer, ['solution_ids' => [$shared->id]]))->assertSessionHasErrors('solution_ids.0');
        $this->put("/plans/{$plan->id}", array_merge($offer, ['solution_ids' => [$custom->id]]))->assertSessionHasNoErrors();

        // A plan that already points to a group of shared capabilities is never offered, and the administration says why.
        $plan->solutions()->sync([$shared->id]);
        $plan->unsetRelation('solutions');
        $state = $plan->fresh()->offerState();
        $this->assertSame('incomplete', $state['state']);
        $this->assertStringContainsString('soluciones comerciales', implode(' ', $state['issues']));
        $this->assertFalse(app(SignupCatalog::class)->hasOffers());

        // A solution switched off cannot be chosen either.
        $custom->update(['is_active' => false]);
        $this->put("/plans/{$plan->id}", array_merge($offer, ['solution_ids' => [$custom->id]]))->assertSessionHasErrors('solution_ids.0');
    }

    // --- the offer changes while someone is signing up ---------------------------------------------------------------

    public function test_a_plan_unpublished_or_changed_before_the_contract_is_confirmed_never_charges_silently_other_conditions(): void
    {
        $plan = $this->plan('Starter');
        $this->toSummary($plan);

        $plan->update(['is_public' => false]); // unpublished while the customer reads the summary
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');
        $this->assertSame(SignupRequest::DRAFT, SignupRequest::firstOrFail()->status);
        $this->assertNull(SignupRequest::firstOrFail()->accepted_at);

        $plan->update(['is_public' => true, 'price_monthly_cents' => 9990000]); // republished at another price
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');
        $this->assertSame(SignupRequest::DRAFT, SignupRequest::firstOrFail()->status, 'nothing is accepted at a price the customer did not see');
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('selected.snapshot.amount', 99900));
    }

    public function test_after_the_terms_are_accepted_the_payment_is_checked_against_what_was_shown_and_a_changed_plan_is_reported(): void
    {
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $this->assertSame(['dashboard', 'reports'], $request->modules_snapshot);

        // The administration changes the price and the modules AFTER acceptance.
        $plan->update(['price_monthly_cents' => 9990000]);
        $plan->modules()->attach(Module::where('key', 'customers')->value('id'));
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->once()->with('signup_plan_changed_after_acceptance', \Mockery::type('array'));

        // The money is compared with the snapshot the customer accepted, not with the new price.
        $this->assertSame(4990000, $request->price_cents);
        $this->pay($request);

        $this->assertSame(SignupRequest::COMPLETED, $request->fresh()->status);
        $this->assertSame(4990000, $request->fresh()->price_cents);
    }

    public function test_a_plan_unpublished_after_the_acceptance_still_serves_the_customer_who_paid_under_those_terms(): void
    {
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);

        $plan->update(['is_public' => false]); // withdrawn from the catalog, still active
        $this->pay($request);

        $workspace = Workspace::where('code', 'CLINICA-CENTRAL')->firstOrFail();
        $this->assertSame(Subscription::ACTIVE, Subscription::where('workspace_id', $workspace->id)->value('status'));
        $this->assertEqualsCanonicalizing(['dashboard', 'reports'], app(ModuleAccess::class)->keys($workspace->fresh()));
    }

    // --- a paid contract that could not be provisioned: reconciliation -------------------------------------------

    public function test_a_paid_contract_that_failed_is_listed_with_no_personal_data_and_is_completed_on_retry(): void
    {
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $plan->update(['is_active' => false]); // the plan can not be applied

        try {
            $this->pay($request);
            $this->fail('an inactive plan is not applied');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Workspace::count());
        $this->assertCount(1, $this->service()->stuck());

        // Read only: it lists, it changes nothing, it prints ids and dates only, and it exits with failure so it is noticed.
        $this->assertSame(1, Artisan::call('signup:reconcile'));
        $output = Artisan::output();
        $this->assertStringContainsString("Solicitud {$request->id}", $output);
        foreach ([self::EMAIL, $this->nit(), '+57 300 111 2233', 'Clínica Central', 'CLINICA-CENTRAL', 'TX-1'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertSame(0, Workspace::count());

        // The diagnosis blocks while a paid contract is stuck.
        $this->assertSame(1, Artisan::call('integrations:diagnose'));
        $this->assertStringContainsString('1 contratación(es) pagada(s) sin aprovisionar', Artisan::output());

        // Retrying before the cause is fixed does not create half a customer; after fixing it, it completes once.
        $this->assertSame(1, Artisan::call('signup:reconcile', ['--retry' => true]));
        $this->assertSame(0, Workspace::count());
        $plan->update(['is_active' => true]);
        $this->assertSame(0, Artisan::call('signup:reconcile', ['--retry' => true]));

        $this->assertSame(SignupRequest::COMPLETED, $request->fresh()->status);
        $this->assertSame(1, Workspace::count());
        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertSame(0, Artisan::call('signup:reconcile'));
        $this->assertCount(0, $this->service()->stuck());
    }

    public function test_reconciliation_never_completes_a_contract_without_a_recorded_payment(): void
    {
        $request = $this->contract($this->plan('Starter'));

        $this->assertCount(0, $this->service()->stuck());
        $this->assertSame(0, Artisan::call('signup:reconcile', ['--retry' => true]));

        $this->expectException(ValidationException::class);
        $this->service()->retryProvisioning($request->fresh());
    }

    public function test_the_alerts_of_a_failed_provisioning_carry_no_personal_data(): void
    {
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $plan->update(['is_active' => false]);
        $captured = [];
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->andReturnUsing(function (string $event, array $context = []) use (&$captured) {
            $captured[] = [$event, $context];
        });

        try {
            $this->pay($request);
        } catch (ValidationException) {
        }

        $this->assertSame('signup_provisioning_failed', $captured[0][0]);
        $dump = json_encode($captured);
        foreach ([self::EMAIL, $this->nit(), 'Clínica', 'TX-1', 'ClaveSegura123', $request->public_id] as $private) {
            $this->assertStringNotContainsString($private, $dump);
        }
    }

    // --- expiry and reservations ----------------------------------------------------------------------------------

    public function test_an_abandoned_draft_expires_and_an_unpaid_contract_releases_its_nit_and_workspace_name(): void
    {
        $plan = $this->plan('Starter');
        $this->toSummary($plan);
        $this->travel(config('signup.draft_ttl_hours') + 1)->hours();

        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'email'));
        $this->assertSame(SignupRequest::EXPIRED, SignupRequest::firstOrFail()->status);
        $this->post('/registro/empresa', [])->assertRedirect(route('signup.index'))->assertSessionHas('error');

        // A contract waiting for its payment reserves the name and the NIT only for a limited time.
        $this->flushSession();
        $this->travel(1)->minutes();
        $contract = $this->contract($plan);
        $this->assertFalse($this->service()->workspaceCodeAvailable('clinica-central'));
        $this->travel(8)->days();
        $this->assertTrue($this->service()->workspaceCodeAvailable('clinica-central'), 'the reservation lapsed');
        $this->assertSame(SignupRequest::AWAITING_PAYMENT, $contract->fresh()->status);
    }

    public function test_a_quote_with_a_price_configured_still_never_charges_or_provisions(): void
    {
        $plan = $this->plan('Con precio y cotización', ['dashboard'], ['requires_quote' => true]);
        $request = $this->toSummary($plan);

        $this->get('/registro')->assertInertia(fn ($page) => $page->where('selected.requiresQuote', true)->where('selected.snapshot', null)->where('selected.purchasable', false));
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');
        $this->post('/registro/cotizacion')->assertSessionHasNoErrors();

        $this->assertSame(SignupRequest::QUOTE_REQUESTED, $request->fresh()->status);
        $this->assertNull($request->fresh()->price_cents);
        $this->assertSame([0, 0, 0, 0], [Organization::count(), Workspace::count(), Subscription::count(), User::where('email', self::EMAIL)->count()]);
    }

    // --- a recorded payment is never lost, edited away or overwritten ------------------------------------------------

    private function captureAlerts(): \ArrayObject
    {
        $captured = new \ArrayObject;
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->andReturnUsing(function (string $event, array $context = []) use ($captured) {
            $captured[] = [$event, $context];
        });

        return $captured;
    }

    public function test_a_contract_with_a_recorded_payment_can_not_be_edited_or_cancelled_from_the_browser(): void
    {
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $plan->update(['is_active' => false]);

        try {
            $this->pay($request);
        } catch (ValidationException) {
        }

        // Before: any of these reopened it as a draft (or cancelled it) and the paid contract vanished from the reconciliation.
        $this->post('/registro/empresa', [
            'admin_name' => 'Otra Persona', 'company_name' => 'Otra Empresa', 'tax_id' => $this->nit(), 'phone' => '+57 300 111 2233',
            'workspace_code' => 'otra-empresa', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ])->assertSessionHasErrors('signup');
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('signup');
        $this->post('/registro/cancelar');

        $fresh = $request->fresh();
        $this->assertSame([SignupRequest::AWAITING_PAYMENT, 'Clínica Central'], [$fresh->status, $fresh->company_name]);
        $this->assertNotNull($fresh->paid_at);
        $this->assertCount(1, $this->service()->stuck());
    }

    public function test_a_second_different_transaction_never_replaces_the_recorded_payment_and_is_reported(): void
    {
        $alerts = $this->captureAlerts();
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $plan->update(['is_active' => false]);

        try {
            $this->pay($request, 'TX-1');
        } catch (ValidationException) {
        }
        $plan->update(['is_active' => true]);

        // Paid again with another transaction while the first one was not provisioned yet: the first stays, the contract completes once.
        $this->pay($request, 'TX-2');
        $this->assertSame(['TX-1', SignupRequest::COMPLETED], [$request->fresh()->payment_reference, $request->fresh()->status]);

        // And once completed, yet another transaction creates nothing and is reported.
        $this->pay($request, 'TX-3');
        $this->assertSame(1, Workspace::count());
        $this->assertSame('TX-1', $request->fresh()->payment_reference);
        $duplicates = collect($alerts)->where(0, 'signup_duplicate_payment');
        $this->assertSame(['TX-2', 'TX-3'], $duplicates->map(fn ($a) => $a[1]['transaction'])->values()->all());
        $this->assertSame([['signup', 'transaction'], ['signup', 'transaction']], $duplicates->map(fn ($a) => array_keys($a[1]))->values()->all());

        // The same transaction repeated is not a duplicate.
        $this->pay($request, 'TX-1');
        $this->assertCount(2, collect($alerts)->where(0, 'signup_duplicate_payment'));
    }

    public function test_a_payment_for_a_cancelled_contract_is_refused_and_leaves_the_transaction_id_for_a_person(): void
    {
        $alerts = $this->captureAlerts();
        $request = $this->contract($this->plan('Starter'));
        $this->post('/registro/cancelar');
        $this->assertSame(SignupRequest::CANCELLED, $request->fresh()->status);

        try {
            $this->pay($request, 'TX-LATE');
            $this->fail('a cancelled contract is not provisioned by a late payment');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Workspace::count());
        $this->assertSame([['signup_payment_unexpected_state', ['signup' => $request->id, 'status' => 'cancelled', 'transaction' => 'TX-LATE']]], collect($alerts)->values()->all());
    }

    public function test_a_price_change_after_the_acceptance_takes_the_contract_back_to_a_draft_instead_of_keeping_a_stale_acceptance(): void
    {
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $plan->update(['price_monthly_cents' => 9990000]);

        // The customer comes back and confirms again: stopped, and the old acceptance and checkout no longer stand.
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');

        $fresh = $request->fresh();
        $this->assertSame([SignupRequest::DRAFT, 9990000], [$fresh->status, $fresh->price_cents]);
        $this->assertNull($fresh->accepted_at);
        $this->assertCount(0, $this->service()->stuck());
    }

    public function test_asking_again_for_a_quote_alerts_the_team_once(): void
    {
        $alerts = $this->captureAlerts();
        $this->toSummary($this->plan('Cotizado', ['dashboard'], ['requires_quote' => true]));

        foreach (range(1, 5) as $ignored) {
            $this->post('/registro/cotizacion')->assertSessionHasNoErrors();
        }

        $this->assertCount(1, collect($alerts)->where(0, 'signup_quote_requested'));
    }

    // --- abuse limits and reconciliation scheduling -----------------------------------------------------------------

    public function test_checking_codes_is_limited_per_ip_even_when_the_visitor_drops_the_session(): void
    {
        $this->plan('Starter');

        foreach (range(1, 10) as $ignored) {
            $this->flushSession(); // a new session id each time: it must not open a new bucket
            $this->post('/registro/codigo', ['code' => '123456'])->assertRedirect();
        }

        $this->flushSession();
        $this->post('/registro/codigo', ['code' => '123456'])->assertStatus(429);
    }

    public function test_the_reconciliation_is_scheduled_read_only_and_alerts_once_while_a_paid_contract_is_stuck(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'signup:reconcile'));

        $this->assertNotNull($event, 'the reconciliation is on the scheduler');
        $this->assertStringNotContainsString('--retry', (string) $event->command, 'the scheduled run never retries by itself');
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        $alerts = $this->captureAlerts();
        $plan = $this->plan('Starter');
        $request = $this->contract($plan);
        $plan->update(['is_active' => false]);

        try {
            $this->pay($request);
        } catch (ValidationException) {
        }

        foreach ([1, 2] as $ignored) {
            $this->assertSame(1, Artisan::call('signup:reconcile'));
        }
        $this->assertSame(0, Workspace::count());
        $this->assertSame([['signup_paid_unprovisioned', ['count' => 1, 'oldest' => $request->id]]], collect($alerts)->where(0, 'signup_paid_unprovisioned')->values()->all());

        // The retry that works leaves a trace of what it did.
        $plan->update(['is_active' => true]);
        $this->assertSame(0, Artisan::call('signup:reconcile', ['--retry' => true, '--limit' => 1]));
        $this->assertSame([['signup_reconciled', ['signup' => $request->id]]], collect($alerts)->where(0, 'signup_reconciled')->values()->all());
    }
}
