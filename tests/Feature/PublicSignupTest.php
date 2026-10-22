<?php

namespace Tests\Feature;

use App\Mail\SignupCodeMail;
use App\Models\LegalAcceptance;
use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\SignupRequest;
use App\Models\SignupVerification;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceModuleOverride;
use App\Payments\PaymentConfirmation;
use App\Payments\PaymentGateway;
use App\Services\ModuleAccess;
use App\Services\ModuleCatalog;
use App\Services\OperationalAlert;
use App\Services\SignupService;
use App\Support\Nit;
use Database\Seeders\ModuleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The public sign-up, end to end: email code -> company and Workspace -> solution -> plan -> summary -> payment -> Workspace. No payment provider exists:
 * the payment is "confirmed" in these tests only by calling the integration point (`SignupService::confirmPayment`) the provider's verified notification
 * will call, never by the application, and the mail is faked (no message leaves).
 */
class PublicSignupTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ana@empresa.co';

    private Plan $starter;

    private Plan $pro;

    private Plan $custom;

    private ModuleCategory $health;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seedRoleCatalog();
        $this->seed(ModuleCatalogSeeder::class);
        $this->health = ModuleCategory::where('key', 'health')->firstOrFail();
        $this->health->update(['is_public' => true]); // a solution is offered publicly only when an administrator made it public

        // The commercial offer is DATA (Planes), exactly as an administrator would configure it.
        $this->starter = $this->plan('Starter', ['dashboard', 'reports'], 4990000, 'monthly');
        $this->pro = $this->plan('Pro', ['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings'], 9900000, 'monthly');
        $this->custom = Plan::create(['name' => 'Custom', 'description' => 'A tu medida', 'is_public' => true, 'requires_quote' => true]);
        $this->custom->modules()->sync($this->ids(['dashboard']));
    }

    /** @param  list<string>  $modules */
    private function plan(string $name, array $modules, ?int $cents, ?string $period, bool $public = true): Plan
    {
        $plan = Plan::create([
            'name' => $name, 'description' => "Plan {$name}", 'module_category_id' => $this->health->id, 'price_monthly_cents' => $period === 'monthly' ? $cents : null, 'price_yearly_cents' => $period === 'yearly' ? $cents : null,
            'currency' => $cents ? 'COP' : null, 'is_public' => $public,
        ]);
        $plan->modules()->sync($this->ids($modules));

        return $plan;
    }

    /** @param  list<string>  $keys @return list<int> */
    private function ids(array $keys): array
    {
        return Module::whereIn('key', $keys)->pluck('id')->all();
    }

    private function service(): SignupService
    {
        return app(SignupService::class);
    }

    private function nit(string $body = '900123456'): string
    {
        return $body.'-'.Nit::checkDigit($body);
    }

    /** The last code mailed to the address. */
    private function mailedCode(string $email = self::EMAIL): string
    {
        return Mail::sent(SignupCodeMail::class)->filter(fn ($mail) => $mail->hasTo($email))->last()->code;
    }

    /** Email -> code: leaves the session with a verified draft. */
    private function verified(string $email = self::EMAIL): SignupRequest
    {
        $this->post('/registro/correo', ['email' => $email])->assertRedirect(route('signup.index'));
        $this->post('/registro/codigo', ['code' => $this->mailedCode($email)])->assertSessionHasNoErrors()->assertRedirect(route('signup.index'));

        return SignupRequest::where('email', $email)->latest('id')->firstOrFail();
    }

    private function company(array $override = []): array
    {
        return $override + [
            'admin_name' => 'Ana Pérez', 'company_name' => 'Clínica Central', 'tax_id' => $this->nit(), 'phone' => '+57 300 111 2233',
            'workspace_code' => 'clinica-central', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ];
    }

    /** Walks the wizard to the summary of a plan. */
    private function toSummary(?Plan $plan = null, array $company = [], string $email = self::EMAIL): SignupRequest
    {
        $plan ??= $this->starter;
        $this->verified($email);
        $this->post('/registro/empresa', $this->company($company))->assertSessionHasNoErrors();
        $this->post('/registro/solucion', ['solution' => $plan->module_category_id ? (string) $plan->module_category_id : 'custom'])->assertSessionHasNoErrors();
        $this->post('/registro/plan', ['plan_id' => $plan->id])->assertSessionHasNoErrors();

        return SignupRequest::where('email', $email)->latest('id')->firstOrFail();
    }

    private function accepted(): array
    {
        return array_keys($this->service()->requiredDocuments());
    }

    private function pay(SignupRequest $request, ?int $cents = null, string $currency = 'COP', string $tx = 'TX-1'): SignupRequest
    {
        return $this->service()->confirmPayment(new PaymentConfirmation($request->public_id, $tx, $cents ?? $request->price_cents, $currency));
    }

    // --- entry and navigation -----------------------------------------------------------------------------------

    public function test_the_login_offers_the_sign_up_and_the_wizard_opens_for_a_guest_without_a_workspace(): void
    {
        $this->assertStringContainsString("route('signup.index')", file_get_contents(base_path('resources/js/Pages/Auth/PreLogin.jsx')));
        $this->assertStringContainsString('¿Aún no tienes cuenta?', file_get_contents(base_path('resources/js/Pages/Auth/PreLogin.jsx')));

        $this->get('/registro')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Signup/Wizard')->where('step', 'email')->has('steps', 6));
        // A signed-in person has no reason to sign up.
        $this->actingAs(User::factory()->create())->get('/registro')->assertRedirect();
    }

    public function test_the_wizard_walks_every_step_and_stops_at_a_pending_payment_creating_nothing_definitive(): void
    {
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'email'));
        $this->post('/registro/correo', ['email' => self::EMAIL]);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'code')->where('email', self::EMAIL));
        $this->post('/registro/codigo', ['code' => $this->mailedCode()]);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'company'));
        $this->post('/registro/empresa', $this->company())->assertSessionHasNoErrors();
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'solution')->has('solutions', 2)); // Salud and Personalizado
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id]);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'plan')->has('plans', 2));
        $this->post('/registro/plan', ['plan_id' => $this->starter->id]);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'summary')->where('selected.name', 'Starter')->has('documents', 2));

        // Back keeps what was typed.
        $this->post('/registro/atras', ['to' => 'company']);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'company')->where('draft.companyName', 'Clínica Central')->where('draft.workspaceCode', 'CLINICA-CENTRAL')->where('draft.hasPassword', true));

        $this->post('/registro/atras', ['to' => 'summary']);
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertRedirect(route('signup.index'));
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'payment')->where('payment.status', 'awaiting_payment')->where('payment.available', false));

        // Navigating created nothing definitive: no company, Workspace, account, subscription or module.
        $this->assertSame(0, Organization::count());
        $this->assertSame(0, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->assertSame(0, User::where('email', self::EMAIL)->count());
        $this->assertSame(0, Subscription::count());
        $this->get('/registro/bienvenida')->assertRedirect(route('signup.index')); // never a success screen before the payment
    }

    /** @return array<string, array{0: ?string}> */
    public static function taxIds(): array
    {
        return ['blank' => [null], 'foreign with letters and separators' => ['GB 123 4567 89'], 'colombian' => ['900.123.456-7']];
    }

    #[DataProvider('taxIds')]
    public function test_the_whole_journey_works_with_an_optional_tax_id_up_to_the_pending_payment(?string $taxId): void
    {
        $this->verified();
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'company'));

        // Step 2 -> 3: no HTTP exception, the draft keeps what was typed (tax id as typed, or null) and the solutions come from the public offer.
        $this->post('/registro/empresa', $this->company(['tax_id' => $taxId]))->assertSessionHasNoErrors()->assertRedirect(route('signup.index'));
        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page->where('step', 'solution')->where('draft.taxId', $taxId === '900.123.456-7' ? Nit::normalize($taxId) ?? $taxId : $taxId)->has('solutions', 2));
        $this->assertSame(1, Organization::where('tax_id', $taxId)->count() + 1, 'nothing definitive: no Organization was created');

        $this->post('/registro/solucion', ['solution' => (string) $this->health->id])->assertSessionHasNoErrors();
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'plan')->has('plans', 2));
        $this->post('/registro/plan', ['plan_id' => $this->starter->id])->assertSessionHasNoErrors();
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'summary')->where('selected.name', 'Starter')->where('selected.snapshot.amount', 49900)->where('selected.snapshot.period', 'monthly'));

        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertRedirect(route('signup.index'));
        // The plan changes after the customer accepted: what was shown and accepted is what is kept (a change BEFORE confirming stops the contract, see below).
        $this->starter->update(['price_monthly_cents' => 99900000]);
        $request = SignupRequest::where('email', self::EMAIL)->firstOrFail();

        $this->assertSame([SignupRequest::AWAITING_PAYMENT, 4990000, 'monthly', $taxId === '900.123.456-7' ? (Nit::normalize($taxId) ?? $taxId) : $taxId], [$request->status, $request->price_cents, $request->billing_period, $request->tax_id]);
        $this->assertSame(['dashboard', 'reports'], collect($request->modules_snapshot)->sort()->values()->all());
        $this->assertEqualsCanonicalizing($this->accepted(), array_keys($request->accepted_documents));
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'payment')->where('payment.status', 'awaiting_payment')->where('payment.available', false));
        $this->assertSame([0, 0, 0], [Workspace::where('code', 'CLINICA-CENTRAL')->count(), User::where('email', self::EMAIL)->count(), Subscription::count()], 'no payment was simulated: nothing is active');
    }

    public function test_a_manipulated_request_cannot_choose_a_plan_that_is_not_public_active_or_of_the_selected_solution(): void
    {
        $other = ModuleCategory::where('key', '!=', 'health')->firstOrFail();
        $private = $this->plan('Privado', ['dashboard'], 1000000, 'monthly', false);
        $inactive = $this->plan('Inactivo', ['dashboard'], 1000000, 'monthly');
        $inactive->update(['is_active' => false]);
        $foreign = $this->plan('Ajeno', ['dashboard'], 1000000, 'monthly');
        $foreign->update(['module_category_id' => $other->id]);

        $this->toSummary();
        $this->post('/registro/atras', ['to' => 'plan']);

        foreach ([$private, $inactive, $foreign] as $plan) {
            $this->post('/registro/plan', ['plan_id' => $plan->id])->assertSessionHasErrors();
            $this->assertNotSame($plan->id, SignupRequest::firstOrFail()->plan_id);
        }

        $this->post('/registro/solucion', ['solution' => '999999'])->assertSessionHasErrors('solution');
    }

    public function test_a_lost_or_expired_session_sends_the_visitor_back_to_the_wizard_with_a_message_not_an_exception(): void
    {
        $this->verified();
        $this->flushSession(); // the cookie of the browser no longer matches any sign-up

        foreach (['empresa' => $this->company(), 'solucion' => ['solution' => '1'], 'plan' => ['plan_id' => $this->starter->id], 'contratar' => ['accepted' => ['terms']], 'cotizacion' => []] as $path => $payload) {
            $this->post("/registro/{$path}", $payload)->assertRedirect(route('signup.index'))->assertSessionHas('error');
        }

        $this->get('/registro')->assertOk()->assertInertia(fn ($page) => $page->where('step', 'email'));
    }

    public function test_a_step_cannot_be_reached_or_posted_without_verifying_the_email(): void
    {
        foreach (['empresa' => $this->company(), 'solucion' => ['solution' => '1'], 'plan' => ['plan_id' => $this->starter->id], 'contratar' => ['accepted' => ['terms']], 'cotizacion' => []] as $path => $payload) {
            $this->post("/registro/{$path}", $payload)->assertRedirect(route('signup.index'))->assertSessionHas('error');
        }

        $this->post('/registro/correo', ['email' => self::EMAIL]); // a code was sent but not verified
        $this->post('/registro/empresa', $this->company())->assertRedirect(route('signup.index'))->assertSessionHas('error');
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'code'));

        // Going "forward" by URL state is not possible either: the server only honors steps already reached.
        $this->post('/registro/atras', ['to' => 'summary']);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'code'));
        $this->assertSame(0, SignupRequest::count());
    }

    // --- the email code ----------------------------------------------------------------------------------------

    public function test_a_wrong_expired_or_reused_code_is_refused_and_the_code_is_stored_only_hashed(): void
    {
        $this->post('/registro/correo', ['email' => self::EMAIL]);
        $code = $this->mailedCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $stored = SignupVerification::firstOrFail();
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertStringNotContainsString($code, json_encode($stored->toArray()));

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/registro/codigo', ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertSame(1, $stored->fresh()->attempts);
        $this->assertSame(0, SignupRequest::count());

        $this->post('/registro/codigo', ['code' => $code])->assertSessionHasNoErrors();
        $this->assertSame(1, SignupRequest::count());

        // The same code does not work again (consumed), neither for a new session.
        $this->flushSession();
        $this->post('/registro/correo', ['email' => 'otra@empresa.co']);
        $this->post('/registro/codigo', ['code' => $code])->assertSessionHasErrors('code');

        // An expired code is refused.
        $this->flushSession();
        $this->travel(2)->minutes(); // past the resend cooldown
        $this->post('/registro/correo', ['email' => 'tarde@empresa.co'])->assertSessionHasNoErrors();
        $late = $this->mailedCode('tarde@empresa.co');
        $this->travel(config('signup.code_ttl_minutes') + 1)->minutes();
        $this->post('/registro/codigo', ['code' => $late])->assertSessionHasErrors('code');
    }

    public function test_the_attempts_per_code_and_the_sends_per_address_are_limited(): void
    {
        $this->post('/registro/correo', ['email' => self::EMAIL]);
        $code = $this->mailedCode();
        $wrong = $code === '123456' ? '654321' : '123456';

        foreach (range(1, config('signup.code_max_attempts')) as $_) {
            $this->post('/registro/codigo', ['code' => $wrong])->assertSessionHasErrors('code');
        }

        // After the limit not even the correct code works.
        $this->post('/registro/codigo', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertSame(0, SignupRequest::count());

        // Resending is limited by a cooldown.
        $this->post('/registro/correo', ['email' => self::EMAIL])->assertSessionHasErrors('email');
        $this->assertStringContainsString('segundos', session('errors')->first('email'));
    }

    public function test_sending_a_code_does_not_reveal_accounts_but_an_address_that_proved_ownership_is_told(): void
    {
        User::factory()->create(['email' => 'existe@empresa.co']);

        $a = $this->post('/registro/correo', ['email' => 'existe@empresa.co']);
        $this->flushSession();
        $this->travel(2)->minutes();
        $b = $this->post('/registro/correo', ['email' => 'nuevo@empresa.co']);
        $this->assertSame($a->getStatusCode(), $b->getStatusCode());
        $this->assertSame($a->headers->get('Location'), $b->headers->get('Location'));
        $this->assertSame([], session('errors')?->all() ?? []);

        // Only after the code proves the address is it said that an account exists, and no request is created.
        $this->flushSession();
        $this->post('/registro/correo', ['email' => 'existe2@empresa.co']);
        User::factory()->create(['email' => 'existe2@empresa.co']);
        $this->post('/registro/codigo', ['code' => $this->mailedCode('existe2@empresa.co')])->assertSessionHasErrors('email');
        $this->assertSame(0, SignupRequest::where('email', 'existe2@empresa.co')->count());
    }

    // --- company, Workspace and NIT -----------------------------------------------------------------------------

    public function test_a_colombian_nit_is_normalized_but_a_wrong_check_digit_is_no_longer_a_reason_to_refuse(): void
    {
        $this->assertSame('900123456-'.Nit::checkDigit('900123456'), Nit::normalize('900.123.456-'.Nit::checkDigit('900123456')));
        $this->assertSame('900123456-'.Nit::checkDigit('900123456'), Nit::normalize('900123456'.Nit::checkDigit('900123456')));
        $this->assertNull(Nit::normalize('abc'));

        $this->verified();
        $this->post('/registro/empresa', $this->company(['tax_id' => '900.123.456-'.Nit::checkDigit('900123456')]))->assertSessionHasNoErrors();
        $this->assertSame($this->nit(), SignupRequest::firstOrFail()->tax_id);
    }

    public function test_the_tax_id_is_optional_and_a_blank_one_is_stored_as_null(): void
    {
        $this->verified();

        foreach ([null, '', '   '] as $blank) {
            $this->post('/registro/empresa', $this->company(['tax_id' => $blank]))->assertSessionHasNoErrors();
            $this->assertNull(SignupRequest::firstOrFail()->tax_id);
        }
    }

    public function test_a_foreign_tax_id_is_accepted_and_kept_as_typed_without_a_check_digit_rule(): void
    {
        $this->verified();

        foreach (['GB 123 4567 89', 'RFC-ABC010101AB1', '12.345.678/0001-95', '900123456-0'] as $id) {
            $this->post('/registro/empresa', $this->company(['tax_id' => $id]))->assertSessionHasNoErrors();
            $this->assertSame($id, SignupRequest::firstOrFail()->tax_id, 'the value typed is kept');
        }

        $this->post('/registro/empresa', $this->company(['tax_id' => str_repeat('A', 21)]))->assertSessionHasErrors('tax_id');
        $this->post('/registro/empresa', $this->company(['tax_id' => 'AB<script>']))->assertSessionHasErrors('tax_id');
    }

    public function test_the_same_tax_id_of_an_existing_company_is_still_not_registered_again_whatever_its_case(): void
    {
        Organization::create(['name' => 'Ya existe', 'tax_id' => 'ab-123']);
        $this->verified();

        $this->post('/registro/empresa', $this->company(['tax_id' => 'AB-123']))->assertSessionHasErrors('tax_id');
    }

    public function test_the_workspace_name_availability_follows_the_real_rules(): void
    {
        $organization = Organization::create(['name' => 'CCG']);
        Workspace::create(['organization_id' => $organization->id, 'code' => 'YA_EXISTE', 'name' => 'Existe']);
        Workspace::create(['organization_id' => $organization->id, 'code' => config('workspace.admin_code'), 'name' => 'Admin']);

        $this->verified();
        $this->getJson('/registro/disponibilidad?code=nuevo-ws')->assertOk()->assertJson(['available' => true]);
        $this->getJson('/registro/disponibilidad?code=ya_existe')->assertJson(['available' => false]);
        $this->getJson('/registro/disponibilidad?code='.config('workspace.admin_code'))->assertJson(['available' => false]);
        $this->getJson('/registro/disponibilidad?code=admin')->assertJson(['available' => false]); // reserved
        $this->getJson('/registro/disponibilidad?code=a b')->assertStatus(422);

        $this->post('/registro/empresa', $this->company(['workspace_code' => 'ya_existe']))->assertSessionHasErrors('workspace_code');
        $this->post('/registro/empresa', $this->company(['workspace_code' => 'ab']))->assertSessionHasErrors('workspace_code');

        // Another sign-up in progress reserves its name; its own draft can keep it.
        $this->post('/registro/empresa', $this->company())->assertSessionHasNoErrors();
        $this->post('/registro/empresa', $this->company())->assertSessionHasNoErrors();
        $this->flushSession();
        $this->travel(2)->minutes();
        $this->verified('otra@empresa.co');
        $this->post('/registro/empresa', $this->company(['tax_id' => $this->nit('800200300')]))->assertSessionHasErrors('workspace_code');
    }

    public function test_a_company_already_registered_is_not_registered_again_and_the_answer_does_not_say_whose_it_is(): void
    {
        Organization::create(['name' => 'Ya registrada', 'tax_id' => $this->nit()]);
        $this->verified();

        $this->post('/registro/empresa', $this->company())->assertSessionHasErrors('tax_id');
        $this->assertStringNotContainsString('Ya registrada', session('errors')->first('tax_id'));
    }

    // --- solutions, plans and prices come from the configuration -----------------------------------------------

    public function test_solutions_plans_prices_and_modules_are_read_from_the_commercial_configuration(): void
    {
        $this->plan('Oculto', ['dashboard'], 100000, 'monthly', public: false);
        $inactive = $this->plan('Apagado', ['dashboard'], 100000, 'monthly');
        $inactive->update(['is_active' => false]);
        $this->toSummary();
        $this->post('/registro/atras', ['to' => 'solution']);

        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'solution')
            ->where('solutions.0.name', $this->health->name)->where('solutions.0.plansCount', 2)->where('solutions.0.modulesCount', 5)
            ->where('solutions.1.name', 'Personalizado')->where('solutions.1.custom', true));

        $this->post('/registro/atras', ['to' => 'plan']);
        $props = $this->get('/registro')->viewData('page')['props'];
        $this->assertSame(['Starter', 'Pro'], array_column($props['plans'], 'name'));
        $this->assertSame(['dashboard', 'reports'], array_column($props['plans'][0]['modules'], 'key'));
        $this->assertSame(49900, $props['plans'][0]['prices'][0]['amount']);
        $this->assertSame('COP', $props['plans'][0]['prices'][0]['currency']);
        $this->assertSame('monthly', $props['plans'][0]['prices'][0]['period']);

        // Changing the configuration changes what is offered, with no code involved.
        $this->starter->update(['price_monthly_cents' => 5500000]);
        $this->starter->modules()->attach($this->ids(['customers']));
        $this->post('/registro/atras', ['to' => 'plan']);
        $props = $this->get('/registro')->viewData('page')['props'];
        $this->assertSame(55000, $props['plans'][0]['prices'][0]['amount']);
        $this->assertContains('customers', array_column($props['plans'][0]['modules'], 'key'));
        $this->pro->update(['is_public' => false]);
        $this->post('/registro/atras', ['to' => 'plan']);
        $this->assertSame(['Starter'], array_column($this->get('/registro')->viewData('page')['props']['plans'], 'name'));
    }

    public function test_the_custom_solution_exists_only_while_a_public_plan_is_sold_by_quote(): void
    {
        $this->custom->update(['is_public' => false]);
        $this->verified();
        $this->post('/registro/empresa', $this->company());

        $this->get('/registro')->assertInertia(fn ($page) => $page->has('solutions', 1)->where('solutions.0.custom', false));
        $this->post('/registro/solucion', ['solution' => 'custom'])->assertSessionHasErrors('solution');
    }

    public function test_a_plan_without_a_usable_price_is_not_offered_and_cannot_be_chosen(): void
    {
        $free = $this->plan('Sin precio', ['dashboard'], null, null);
        $this->verified();
        $this->post('/registro/empresa', $this->company());
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id]);

        $this->assertNotContains('Sin precio', array_column($this->get('/registro')->viewData('page')['props']['plans'], 'name'));
        $this->post('/registro/plan', ['plan_id' => $free->id])->assertSessionHasErrors('plan');
        $this->assertNull(SignupRequest::firstOrFail()->plan_id);
    }

    public function test_the_browser_cannot_choose_a_price_a_module_or_a_plan_that_is_not_offered(): void
    {
        $other = ModuleCategory::where('key', 'ecommerce')->firstOrFail();
        $foreign = Plan::create(['name' => 'Tienda', 'module_category_id' => $other->id, 'price_monthly_cents' => 100, 'currency' => 'COP', 'is_public' => true]);
        $this->verified();
        $this->post('/registro/empresa', $this->company());
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id]);

        $this->post('/registro/plan', ['plan_id' => $foreign->id])->assertSessionHasErrors('plan'); // another solution's plan
        $this->post('/registro/plan', ['plan_id' => 99999])->assertSessionHasErrors('plan');
        $this->post('/registro/plan', ['plan_id' => $this->starter->id, 'price' => 1, 'price_cents' => 1, 'currency' => 'USD', 'module_ids' => [1, 2, 3]])->assertSessionHasNoErrors();

        $request = SignupRequest::firstOrFail();
        $this->assertSame(4990000, $request->price_cents);
        $this->assertSame('COP', $request->currency);
    }

    public function test_a_price_change_between_the_summary_and_the_confirmation_stops_the_contract(): void
    {
        $this->toSummary();
        $this->starter->update(['price_monthly_cents' => 9990000]);

        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');
        $this->assertSame(9990000, SignupRequest::firstOrFail()->price_cents, 'the new summary shows the new price');
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasNoErrors();
    }

    public function test_the_terms_must_be_accepted_to_contract_and_their_version_is_kept(): void
    {
        $request = $this->toSummary();

        $this->post('/registro/contratar', ['accepted' => ['terms']])->assertSessionHasErrors('terms');
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasNoErrors();

        $this->assertSame(config('legal.documents.terms.version'), $request->fresh()->accepted_documents['terms']);
        $this->assertNotNull($request->fresh()->accepted_at);
    }

    // --- quote ------------------------------------------------------------------------------------------------

    public function test_a_plan_sold_by_quote_records_a_request_and_never_a_checkout_or_a_workspace(): void
    {
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->twice();
        $request = $this->toSummary($this->custom);

        $this->get('/registro')->assertInertia(fn ($page) => $page->where('selected.requiresQuote', true)->where('selected.snapshot', null));
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan'); // no immediate purchase
        $this->post('/registro/cotizacion')->assertRedirect(route('signup.index'));

        $this->assertSame(SignupRequest::QUOTE_REQUESTED, $request->fresh()->status);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'payment')->where('payment.status', 'quote_requested'));
        $this->assertSame(0, Workspace::where('code', 'CLINICA-CENTRAL')->count());
        $this->assertSame(0, Subscription::count());

        // A quote can not be turned into a paid activation.
        $this->expectException(ValidationException::class);
        $this->pay($request->fresh(), 0);
    }

    // --- the verified payment -> Workspace, modules and access --------------------------------------------------

    public function test_nothing_is_active_and_no_success_is_shown_until_a_payment_is_verified(): void
    {
        $request = $this->toSummary();
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);

        // No gateway is bound: nothing can charge, and the application can not mark a payment.
        $this->assertFalse(app(PaymentGateway::class)->isConfigured());
        $this->assertSame(SignupRequest::AWAITING_PAYMENT, $request->fresh()->status);
        $this->get('/registro/bienvenida')->assertRedirect(route('signup.index'));
        $this->assertSame(0, Workspace::count());
        $this->assertNull($request->fresh()->payment_reference);
        $this->assertNull($request->fresh()->paid_at);
    }

    public function test_a_verified_payment_creates_the_workspace_applies_the_plan_and_opens_the_welcome(): void
    {
        $request = $this->toSummary($this->pro);
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);

        $this->pay($request);

        $request = $request->fresh();
        $this->assertSame(SignupRequest::COMPLETED, $request->status);
        $workspace = Workspace::where('code', 'CLINICA-CENTRAL')->firstOrFail();
        $this->assertTrue($workspace->is_active);
        $this->assertSame($this->pro->id, $workspace->plan_id);
        $this->assertSame('Clínica Central', $workspace->organization->name);
        $this->assertSame($this->nit(), $workspace->organization->tax_id);
        $this->assertSame(Subscription::ACTIVE, Subscription::where('workspace_id', $workspace->id)->value('status'));
        $this->assertEqualsCanonicalizing(['dashboard', 'reports', 'service_catalog', 'professionals', 'bookings'], app(ModuleAccess::class)->keys($workspace->fresh()));

        $admin = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('admin', $workspace->users()->where('users.id', $admin->id)->first()->pivot->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(\Hash::check('ClaveSegura123', $admin->password));
        $this->assertSame(2, LegalAcceptance::where('user_id', $admin->id)->count(), 'the accepted versions are recorded for the account');

        $this->get('/registro/bienvenida')->assertOk()->assertInertia(fn ($page) => $page->component('Signup/Welcome')->where('workspace.code', 'CLINICA-CENTRAL')->where('plan.name', 'Pro')->where('solution', $this->health->name));
        // The contracted modules are really usable by the new administrator, with no manual step.
        $this->flushSession();
        $this->actingAs($admin)->withSession(['workspace_id' => $workspace->id])->get('/reports')->assertOk();
        $this->get('/customers')->assertForbidden(); // not contracted
    }

    public function test_a_repeated_notification_creates_nothing_twice_and_keeps_the_workspace_exceptions(): void
    {
        $request = $this->toSummary();
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);
        $this->pay($request);

        $workspace = Workspace::where('code', 'CLINICA-CENTRAL')->firstOrFail();
        app(ModuleCatalog::class)->setWorkspaceOverrides($workspace, [], [$this->ids(['reports'])[0]], 'Contrato parcial', null);
        $counts = [Organization::count(), Workspace::count(), User::count(), Subscription::count(), WorkspaceModuleOverride::count(), LegalAcceptance::count()];

        foreach (range(1, 3) as $_) {
            $this->pay($request->fresh());
        }
        $this->pay($request->fresh(), tx: 'TX-OTRO'); // even a different transaction id for an already completed sign-up changes nothing

        $this->assertSame($counts, [Organization::count(), Workspace::count(), User::count(), Subscription::count(), WorkspaceModuleOverride::count(), LegalAcceptance::count()]);
        $this->assertNotContains('reports', app(ModuleAccess::class)->keys($workspace->fresh()), 'the exception survives');
        $this->assertSame('TX-1', $request->fresh()->payment_reference);
    }

    public function test_a_payment_that_does_not_match_the_contract_is_refused_and_changes_nothing(): void
    {
        $request = $this->toSummary();
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->times(3);

        foreach ([[1, 'COP', 'amount'], [null, 'USD', 'amount']] as [$cents, $currency, $field]) {
            try {
                $this->pay($request->fresh(), $cents, $currency);
                $this->fail('a payment that does not match must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        try {
            $this->service()->confirmPayment(new PaymentConfirmation('00000000-0000-0000-0000-000000000000', 'TX-X', 4990000, 'COP'));
            $this->fail('an unknown reference must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }

        $this->assertSame(SignupRequest::AWAITING_PAYMENT, $request->fresh()->status);
        $this->assertNull($request->fresh()->payment_reference);
        $this->assertSame(0, Workspace::count());
        $this->assertSame(0, User::where('email', self::EMAIL)->count());
    }

    public function test_a_provisioning_failure_keeps_the_payment_and_the_retry_completes_it(): void
    {
        $request = $this->toSummary();
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);
        $this->starter->update(['is_active' => false]); // the plan can not be applied right now
        $this->mock(OperationalAlert::class)->shouldReceive('raise')->once()->with('signup_provisioning_failed', \Mockery::type('array'));

        try {
            $this->pay($request);
            $this->fail('an inactive plan is not applied');
        } catch (ValidationException) {
        }

        $request = $request->fresh();
        $this->assertSame('TX-1', $request->payment_reference, 'the payment is not lost');
        $this->assertSame(SignupRequest::AWAITING_PAYMENT, $request->status);
        $this->assertSame(0, Workspace::count(), 'all or nothing: no half-created company');
        $this->assertSame(0, User::where('email', self::EMAIL)->count());
        $this->get('/registro/bienvenida')->assertRedirect(route('signup.index'));

        $this->starter->update(['is_active' => true]);
        $this->pay($request->fresh());

        $this->assertSame(SignupRequest::COMPLETED, $request->fresh()->status);
        $this->assertSame(1, Workspace::count());
    }

    public function test_two_companies_get_separate_organizations_workspaces_and_members(): void
    {
        $a = $this->toSummary();
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);
        $this->pay($a);

        $this->flushSession();
        $this->travel(2)->minutes();
        $b = $this->toSummary($this->pro, ['company_name' => 'Hospital Norte', 'tax_id' => $this->nit('800200300'), 'workspace_code' => 'hospital-norte'], 'beto@hospital.co');
        $this->post('/registro/contratar', ['accepted' => $this->accepted()]);
        $this->pay($b, tx: 'TX-2');

        $wsA = Workspace::where('code', 'CLINICA-CENTRAL')->firstOrFail();
        $wsB = Workspace::where('code', 'HOSPITAL-NORTE')->firstOrFail();
        $this->assertNotSame($wsA->organization_id, $wsB->organization_id);
        $this->assertSame(['ana@empresa.co'], $wsA->users()->pluck('email')->all());
        $this->assertSame(['beto@hospital.co'], $wsB->users()->pluck('email')->all());
        $this->assertNotContains('bookings', app(ModuleAccess::class)->keys($wsA), 'A keeps its own plan');
        $this->assertContains('bookings', app(ModuleAccess::class)->keys($wsB));

        // The administrator of A is not a member of B and can not enter it.
        $this->flushSession();
        $adminA = User::where('email', 'ana@empresa.co')->firstOrFail();
        $this->actingAs($adminA)->withSession(['workspace_id' => $wsB->id])->get('/reports')->assertRedirect(route('pre-login'));
    }

    // --- block 1: solutions as commercial data, limits shown and frozen ---------------------------------------------------

    private function platformAdmin(): User
    {
        $dev = Workspace::firstOrCreate(['code' => config('workspace.admin_code')], ['organization_id' => Organization::firstOrCreate(['name' => 'CCG'])->id, 'name' => 'Dev']);
        $user = User::factory()->create();
        Role::findOrCreate('plataforma', 'web');
        $dev->users()->attach($user->id, ['role' => 'plataforma']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        return $user;
    }

    public function test_the_offer_and_the_summary_show_the_limits_the_plan_really_sets_and_never_invent_one(): void
    {
        PlanLimit::create(['plan_id' => $this->starter->id, 'metric' => 'users', 'value' => 5]);
        PlanLimit::create(['plan_id' => $this->starter->id, 'metric' => 'ai_executions', 'value' => 1500]);

        $this->verified();
        $this->post('/registro/empresa', $this->company());
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id]);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'plan')
            ->where('plans', fn ($plans) => collect($plans)->firstWhere('name', 'Starter')['limits'] === [
                ['key' => 'users', 'label' => 'Usuarios', 'unit' => 'usuarios', 'value' => 5],
                ['key' => 'locations', 'label' => 'Sedes', 'unit' => 'sedes activas', 'value' => null],
                ['key' => 'ai_executions', 'label' => 'Ejecuciones de IA', 'unit' => 'ejecuciones al mes', 'value' => 1500],
            ] && collect(collect($plans)->firstWhere('name', 'Pro')['limits'])->every(fn ($limit) => $limit['value'] === null), 'a plan without limits says none is defined'));

        $this->post('/registro/plan', ['plan_id' => $this->starter->id]);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'summary')->where('selected.limits.0.value', 5)->where('selected.limits.2.value', 1500)->where('selected.solutionName', $this->health->name));
    }

    public function test_the_limits_accepted_are_kept_and_a_change_before_confirming_stops_the_contract(): void
    {
        $limit = PlanLimit::create(['plan_id' => $this->starter->id, 'metric' => 'users', 'value' => 5]);
        $this->toSummary();

        // The limit changes while the customer reads the summary: nothing is confirmed with a number they did not see.
        $limit->update(['value' => 2]);
        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasErrors('plan');
        $this->assertSame(SignupRequest::DRAFT, SignupRequest::firstOrFail()->status);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'summary')->where('selected.limits.0.value', 2));

        $this->post('/registro/contratar', ['accepted' => $this->accepted()])->assertSessionHasNoErrors();
        $limit->update(['value' => 99]); // later edits of the plan never change what was accepted
        $this->assertSame(2, SignupRequest::firstOrFail()->limits_snapshot['users']);
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'payment')->where('selected.limits.0.value', 2));
    }

    public function test_renaming_a_solution_keeps_its_technical_identity_and_every_relation(): void
    {
        $this->platformAdmin();
        $key = $this->health->key;
        $planIds = Plan::where('module_category_id', $this->health->id)->pluck('id')->sort()->values()->all();

        $this->put("/module-categories/{$this->health->id}", ['name' => 'Clínicas y Bienestar', 'description' => 'Para centros de salud'])->assertSessionHasNoErrors();

        $this->health->refresh();
        $this->assertSame([$key, 'Clínicas y Bienestar'], [$this->health->key, $this->health->name]);
        $this->assertSame($planIds, Plan::where('module_category_id', $this->health->id)->pluck('id')->sort()->values()->all());
        $this->put("/module-categories/{$this->health->id}", ['name' => 'Otro', 'description' => 'x', 'key' => 'hacked'])->assertSessionHasNoErrors();
        $this->assertSame($key, $this->health->fresh()->key, 'the technical key can not be edited');

        auth()->logout();
        $this->flushSession();
        $this->get('/registro')->assertOk();
        $this->verified();
        $this->post('/registro/empresa', $this->company());
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'solution')->where('solutions', fn ($solutions) => collect($solutions)->contains(fn ($s) => $s['key'] === (string) $this->health->id && $s['name'] === 'Otro')));
    }

    public function test_a_solution_switched_off_is_not_offered_but_nothing_is_deleted(): void
    {
        $this->platformAdmin();
        $this->post("/module-categories/{$this->health->id}/deactivate")->assertSessionHasNoErrors();

        auth()->logout();
        $this->flushSession();
        $this->verified();
        $this->post('/registro/empresa', $this->company());
        $this->get('/registro')->assertInertia(fn ($page) => $page->where('step', 'solution')->where('solutions', fn ($solutions) => ! collect($solutions)->contains(fn ($s) => $s['key'] === (string) $this->health->id)));
        $this->post('/registro/solucion', ['solution' => (string) $this->health->id])->assertSessionHasErrors('solution');

        $this->assertSame(2, Plan::where('module_category_id', $this->health->id)->count(), 'its plans are kept');
        $this->assertNotNull($this->starter->fresh());
    }

    // --- administration of the offer -----------------------------------------------------------------------------

    public function test_the_plan_administration_saves_the_commercial_offer_and_validates_it(): void
    {
        $dev = Workspace::create(['organization_id' => Organization::create(['name' => 'CCG'])->id, 'code' => config('workspace.admin_code'), 'name' => 'Dev']);
        $user = User::factory()->create();
        Role::findOrCreate('plataforma', 'web');
        $dev->users()->attach($user->id, ['role' => 'plataforma']);
        $this->actingAs($user)->withSession(['workspace_id' => $dev->id]);

        // A public plan sold online needs a solution, price, currency and period; nothing is filled in.
        $this->post('/plans', ['name' => 'Nuevo', 'is_public' => true])->assertSessionHasErrors(['module_category_id', 'price_monthly']);
        $this->post('/plans', ['name' => 'Sin moneda', 'price_monthly' => 100, 'is_public' => false])->assertSessionHasErrors('currency');

        $this->post('/plans', ['name' => 'Nuevo', 'module_category_id' => $this->health->id, 'price_yearly' => '120000.50', 'currency' => 'COP', 'is_public' => true, 'module_ids' => []])->assertSessionHasNoErrors();
        $plan = Plan::where('name', 'Nuevo')->firstOrFail();
        $this->assertSame(12000050, $plan->price_yearly_cents);
        $this->assertNull($plan->price_monthly_cents, 'a period without a price is not sold');
        $this->assertTrue($plan->is_public);
        $this->assertSame(0, $plan->modules()->count(), 'zero modules is valid');

        // Saving from a form that does not know the offer never erases it.
        $this->put("/plans/{$plan->id}", ['name' => 'Nuevo 2'])->assertSessionHasNoErrors();
        $this->assertSame(12000050, $plan->fresh()->price_yearly_cents);
        $this->assertSame('Nuevo 2', $plan->fresh()->name);

        // A quote plan needs no price.
        $this->post('/plans', ['name' => 'A medida', 'is_public' => true, 'requires_quote' => true])->assertSessionHasNoErrors();
        $this->get('/plans')->assertInertia(fn ($page) => $page->has('commercial.solutions')->has('commercial.currencies'));
    }
}
