<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SignupRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\PaymentConfirmation;
use App\Services\SignupCatalog;
use App\Services\SignupService;
use App\Support\Nit;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One offer, two prices: a plan under its solution sells a monthly price and a yearly total in the same currency. The customer picks the period, the price
 * chosen is a snapshot that a later edit of the plan never changes, a period without its own price is never sold, and nothing here charges or activates
 * anything: the payment is "confirmed" only through the integration point the provider will use.
 */
class PlanPeriodPricingTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ana@empresa.co';

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $this->plan = Plan::create([
            'name' => 'Starter', 'module_category_id' => tap(ModuleCategory::where('key', 'health')->firstOrFail(), fn ($c) => $c->update(['is_public' => true]))->id, 'currency' => 'COP', 'is_public' => true,
            'price_monthly_cents' => 4990000, 'price_yearly_cents' => 49900000,
        ]);
        $this->plan->modules()->sync(Module::whereIn('key', ['dashboard', 'reports'])->pluck('id'));
    }

    private function nit(): string
    {
        return '900123456-'.Nit::checkDigit('900123456');
    }

    private function toPlanStep(): void
    {
        $this->post('/registro/correo', ['email' => self::EMAIL])->assertSessionHasNoErrors();
        $this->post('/registro/codigo', ['code' => Mail::sent(SignupCodeMail::class)->last()->code])->assertSessionHasNoErrors();
        $this->post('/registro/empresa', [
            'admin_name' => 'Ana Pérez', 'company_name' => 'Clínica Central', 'tax_id' => $this->nit(), 'phone' => '+57 300 111 2233',
            'workspace_code' => 'clinica-central', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ])->assertSessionHasNoErrors();
        $this->post('/registro/solucion', ['solution' => (string) $this->plan->module_category_id])->assertSessionHasNoErrors();
    }

    private function request(): SignupRequest
    {
        return SignupRequest::where('email', self::EMAIL)->firstOrFail();
    }

    private function accepted(): array
    {
        return array_keys(app(SignupService::class)->requiredDocuments());
    }

    // --- what the catalog offers ---------------------------------------------------------------------------------

    public function test_the_catalog_lists_every_period_that_has_a_price_and_only_those(): void
    {
        $catalog = app(SignupCatalog::class);
        $prices = $catalog->plans((string) $this->plan->module_category_id)[0]['prices'];

        $this->assertSame(['monthly', 'yearly'], array_column($prices, 'period'));
        $this->assertEquals([49900, 499000], array_column($prices, 'amount'));
        $this->assertSame(['COP', 'COP'], array_column($prices, 'currency'));

        $this->plan->update(['price_yearly_cents' => null]);
        $this->assertSame(['monthly'], array_column($catalog->plans((string) $this->plan->module_category_id)[0]['prices'], 'period'));

        $this->plan->update(['price_monthly_cents' => 0, 'price_yearly_cents' => 49900000]);
        $this->assertSame(['yearly'], $this->plan->fresh()->availablePeriods(), 'zero is not a price');

        $this->plan->update(['currency' => null]);
        $this->assertSame([], $this->plan->fresh()->availablePeriods(), 'no price without its currency');
        $this->assertSame('incomplete', $this->plan->fresh()->offerState()['state']);
    }

    // --- the customer chooses the period -------------------------------------------------------------------------

    public function test_with_two_periods_the_customer_must_choose_and_the_snapshot_keeps_that_price(): void
    {
        $this->toPlanStep();

        // Nothing is chosen for them.
        $this->post('/registro/plan', ['plan_id' => $this->plan->id])->assertSessionHasErrors('period');
        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'weekly'])->assertSessionHasErrors('period');

        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'yearly'])->assertSessionHasNoErrors();
        $request = $this->request();
        $this->assertSame([49900000, 'COP', 'yearly'], [$request->price_cents, $request->currency, $request->billing_period]);

        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'summary')->where('selected.snapshot.period', 'yearly')->where('selected.snapshot.amount', 499000)->where('selected.snapshot.currency', 'COP'));

        // Choosing again switches to the other period with its own price.
        $this->post('/registro/atras', ['to' => 'plan']);
        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'monthly'])->assertSessionHasNoErrors();
        $this->assertSame([4990000, 'monthly'], [$this->request()->price_cents, $this->request()->billing_period]);
    }

    public function test_a_plan_that_sells_one_period_needs_no_choice_and_a_period_it_does_not_sell_is_refused(): void
    {
        $this->plan->update(['price_yearly_cents' => null]);
        $this->toPlanStep();

        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'yearly'])->assertSessionHasErrors('period');
        $this->assertNull($this->request()->plan_id, 'nothing is stored for a period that is not sold');

        $this->post('/registro/plan', ['plan_id' => $this->plan->id])->assertSessionHasNoErrors();
        $this->assertSame([4990000, 'monthly'], [$this->request()->price_cents, $this->request()->billing_period]);
    }

    public function test_a_quote_plan_has_no_period_and_never_a_price(): void
    {
        $this->plan->update(['requires_quote' => true]);
        $this->toPlanStep();

        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'yearly'])->assertSessionHasNoErrors();
        $request = $this->request();
        $this->assertSame([null, null, null], [$request->price_cents, $request->currency, $request->billing_period]);
    }

    // --- an edit of the offer never changes what was chosen --------------------------------------------------------

    public function test_the_accepted_period_and_price_survive_a_later_edit_of_the_plan_and_the_payment_is_checked_against_them(): void
    {
        $this->toPlanStep();
        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'yearly']);
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasNoErrors();
        $request = $this->request();
        $this->assertSame('awaiting_payment', $request->status);

        // The administration reprices both periods and drops the yearly one after the customer accepted.
        $this->plan->update(['price_monthly_cents' => 9990000, 'price_yearly_cents' => null]);
        $request = $request->fresh();
        $this->assertSame([49900000, 'yearly'], [$request->price_cents, $request->billing_period]);

        // The monthly price or any other amount does not confirm the contract; the accepted one does.
        foreach ([9990000, 4990000, 49900001] as $wrong) {
            try {
                app(SignupService::class)->confirmPayment(new PaymentConfirmation($request->public_id, 'TX-BAD', $wrong, 'COP'));
                $this->fail('a payment that is not the accepted price must be refused');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, Workspace::count());

        app(SignupService::class)->confirmPayment(new PaymentConfirmation($request->public_id, 'TX-1', 49900000, 'COP'));
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(1, Subscription::count());
    }

    public function test_confirming_after_the_chosen_period_stopped_being_sold_goes_back_to_a_draft_instead_of_charging_it(): void
    {
        $this->toPlanStep();
        $this->post('/registro/plan', ['plan_id' => $this->plan->id, 'period' => 'yearly']);
        $this->plan->update(['price_yearly_cents' => null]); // the yearly price is withdrawn before the customer confirms

        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');

        $request = $this->request();
        $this->assertSame(['draft', null, null], [$request->status, $request->price_cents, $request->billing_period]);
    }

    // --- administration ----------------------------------------------------------------------------------------------

    private function platformAdmin(): void
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $user = User::factory()->create();
        Role::findOrCreate('plataforma', 'web');
        $dev->users()->attach($user->id, ['role' => 'plataforma']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);
    }

    public function test_the_administrator_saves_both_prices_validated_and_the_page_receives_them(): void
    {
        $this->platformAdmin();
        $offer = ['name' => 'Starter', 'solution_ids' => [$this->plan->module_category_id], 'currency' => 'COP', 'is_public' => true, 'requires_quote' => false];

        $this->put("/plans/{$this->plan->id}", $offer + ['price_monthly' => '59900', 'price_yearly' => '599000.50'])->assertSessionHasNoErrors();
        $this->assertSame([5990000, 59900050], [$this->plan->fresh()->price_monthly_cents, $this->plan->fresh()->price_yearly_cents]);

        // Only one period: the other is simply not sold.
        $this->put("/plans/{$this->plan->id}", $offer + ['price_monthly' => '', 'price_yearly' => '599000'])->assertSessionHasNoErrors();
        $this->assertSame([null, 59900000], [$this->plan->fresh()->price_monthly_cents, $this->plan->fresh()->price_yearly_cents]);

        // Invalid values are refused and change nothing.
        foreach (['-1', 'abc', 'INF', 'NAN', '1e999', '10.999', '99999999999'] as $bad) {
            $this->put("/plans/{$this->plan->id}", $offer + ['price_yearly' => $bad])->assertSessionHasErrors('price_yearly', "refused: {$bad}");
        }
        $this->put("/plans/{$this->plan->id}", ['name' => 'Starter', 'is_public' => false, 'price_monthly' => '10', 'currency' => ''])->assertSessionHasErrors('currency');
        $this->put("/plans/{$this->plan->id}", $offer + ['price_monthly' => '', 'price_yearly' => ''])->assertSessionHasErrors('price_monthly'); // public and online with no price
        $this->assertSame(59900000, $this->plan->fresh()->price_yearly_cents);

        $offerProps = collect($this->get('/plans')->viewData('page')['props']['plans'])->firstWhere('name', 'Starter')['offer'];
        $this->assertEquals([null, 599000], [$offerProps['priceMonthly'], $offerProps['priceYearly']]);
        $this->assertSame(['yearly'], $offerProps['periods']);
        $this->assertSame(['Salud'], $offerProps['solutionNames']);
    }

    public function test_a_client_administrator_cannot_set_prices(): void
    {
        $client = Workspace::create(['organization_id' => Organization::create(['name' => 'Cliente'])->id, 'code' => 'CLIENTE_X', 'name' => 'Cliente']);
        $admin = User::factory()->create();
        $client->users()->attach($admin->id, ['role' => 'admin']);

        $this->actingAs($admin)->withSession(['workspace_id' => $client->id])->put("/plans/{$this->plan->id}", ['name' => 'Starter', 'price_monthly' => '1', 'currency' => 'COP'])->assertForbidden();
        $this->assertSame(4990000, $this->plan->fresh()->price_monthly_cents);
    }

    // --- license administration --------------------------------------------------------------------------------------

    public function test_the_workspace_license_page_shows_plan_solution_and_state_and_offers_the_change_only_to_who_may_assign(): void
    {
        $this->platformAdmin();
        $workspace = Workspace::create(['organization_id' => Organization::create(['name' => 'Clínica'])->id, 'code' => 'CLINICA', 'name' => 'Clínica', 'plan_id' => $this->plan->id]);
        $other = Plan::create(['name' => 'Otro plan', 'module_category_id' => $this->plan->module_category_id]);

        $props = $this->get("/workspaces/{$workspace->id}/modules")->assertOk()->viewData('page')['props'];
        $this->assertSame('Starter', $props['data']['plan']['name']);
        $this->assertSame('Salud', $props['data']['plan']['solution']);
        $this->assertTrue($props['data']['plan']['explicit']);
        $this->assertArrayHasKey('effectiveCount', $props['data']);
        $this->assertContains($other->id, array_column($props['plans'], 'value'));

        // The change goes through the same guarded endpoint, and an inactive plan is refused.
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $workspace->fresh()->plan_id);
        $other->update(['is_active' => false]);
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $this->plan->id])->assertSessionHasNoErrors();
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $other->id])->assertSessionHasErrors('plan_id');
        $this->assertSame($this->plan->id, $workspace->fresh()->plan_id);

        // The internal Workspace has no license page and keeps every module.
        $dev = Workspace::where('code', config('workspace.admin_code'))->firstOrFail();
        $this->get("/workspaces/{$dev->id}/modules")->assertNotFound();
        $this->put("/workspaces/{$dev->id}/plan", ['plan_id' => $this->plan->id])->assertSessionHasErrors('workspace_id');
        $this->assertNull($dev->fresh()->plan_id);

        // A client administrator reaches neither the page nor the change.
        $client = User::factory()->create();
        $workspace->users()->attach($client->id, ['role' => 'admin']);
        $this->actingAs($client)->withSession(['workspace_id' => $workspace->id]);
        $this->get("/workspaces/{$workspace->id}/modules")->assertForbidden();
        $this->put("/workspaces/{$workspace->id}/plan", ['plan_id' => $other->id])->assertForbidden();
    }

    public function test_the_signup_still_states_a_controlled_empty_offer_when_the_price_columns_are_missing(): void
    {
        Schema::table('plans', fn ($table) => $table->dropColumn(['price_monthly_cents', 'price_yearly_cents']));

        $this->assertFalse(app(SignupCatalog::class)->ready());
        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page->where('offersAvailable', false));
        $this->post('/registro/correo', ['email' => self::EMAIL])->assertStatus(503);
    }
}
