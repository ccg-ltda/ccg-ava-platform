<?php

namespace App\Http\Controllers;

use App\Legal\LegalDocuments;
use App\Models\Plan;
use App\Models\SignupRequest;
use App\Models\SignupVerification;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Payments\PaymentGateway;
use App\Services\SignupCatalog;
use App\Services\SignupService;
use App\Services\SignupVerifier;
use App\Support\MailAvailability;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Public sign-up wizard (/registro): email code -> company and Workspace -> solution -> plan -> summary -> payment -> welcome. Open to guests only.
 * The server decides the step from the state of the sign-up request (kept in the server session by its public id, never in the URL), so the browser
 * can not skip a step, choose a price or claim a payment; going back is allowed to any step already reached. Nothing here creates a Workspace or grants
 * a module: that happens only in SignupService::confirmPayment, from the payment provider's verified notification.
 */
class SignupController extends Controller
{
    /** Order of the steps; the wizard can show any step up to the one the state allows. */
    private const STEPS = ['email', 'code', 'company', 'solution', 'plan', 'summary', 'payment'];

    public function __construct(
        private readonly SignupService $signup,
        private readonly SignupVerifier $verifier,
        private readonly SignupCatalog $catalog,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // Migration not applied yet: the wizard opens on its first step and states that nothing can be contracted; no table of the sign-up is read.
        $draft = $this->catalog->ready() ? $this->draft($request) : null;

        if ($draft?->status === SignupRequest::COMPLETED) {
            return redirect()->route('signup.welcome');
        }

        $reached = $this->reached($request, $draft);
        $goto = $request->session()->pull('signup.goto');
        $step = in_array($goto, self::STEPS, true) && array_search($goto, self::STEPS, true) <= array_search($reached, self::STEPS, true) ? $goto : $reached;

        return Inertia::render('Signup/Wizard', [
            'step' => $step,
            'steps' => $this->progress($step),
            'mailUsable' => MailAvailability::usable(),
            'offersAvailable' => $this->catalog->hasOffers(),
            'codeMinutes' => (int) config('signup.code_ttl_minutes'),
            'resendSeconds' => (int) config('signup.code_resend_seconds'),
            'passwordMin' => (int) config('signup.password_min'),
            'email' => $draft?->email ?? $this->pendingEmail($request),
            'draft' => $draft ? $this->draftProps($draft) : null,
            'solutions' => $step === 'solution' ? $this->catalog->solutions() : [],
            'solutionKey' => $draft?->solution_key,
            'plans' => $step === 'plan' && $draft && ($key = $draft->solution_key) ? $this->catalog->plans($key) : [],
            'selected' => $draft?->plan_id && in_array($step, ['summary', 'payment'], true) ? $this->selectedPlan($draft) : null,
            'documents' => $step === 'summary' || $step === 'payment' ? $this->documents() : [],
            'payment' => $step === 'payment' ? ['status' => $draft->status, 'reference' => $draft->public_id, 'available' => app(PaymentGateway::class)->isConfigured()] : null,
        ]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        if (! $this->catalog->hasOffers()) {
            throw ValidationException::withMessages(['email' => 'Actualmente no hay ofertas disponibles para contratar en línea. Contacta con soporte.']);
        }

        $verification = $this->verifier->send($data['email'], $request->ip());
        $request->session()->put('signup.verification_id', $verification->id);
        $request->session()->forget('signup.public_id');

        // The same answer whether or not the address has an account.
        return redirect()->route('signup.index');
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], ['code.regex' => 'El código tiene 6 dígitos.']);
        $verification = SignupVerification::find($request->session()->get('signup.verification_id'));

        if (! $verification) {
            return redirect()->route('signup.index');
        }

        $draft = $this->verifier->verify($verification, $data['code'], $request->ip());
        $request->session()->put('signup.public_id', $draft->public_id);
        $request->session()->forget('signup.verification_id');
        $request->session()->regenerate(); // a new session id once the address is proven

        return redirect()->route('signup.index');
    }

    /** Back to the email step with another address (or to get another code). */
    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget(['signup.public_id', 'signup.goto']);

        return redirect()->route('signup.index');
    }

    public function availability(Request $request): JsonResponse
    {
        $code = (string) $request->query('code', '');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{3,50}$/', $code) === 1, 422);

        return response()->json(['available' => $this->signup->workspaceCodeAvailable($code, $this->draft($request))])->header('Cache-Control', 'no-store');
    }

    public function company(Request $request): RedirectResponse
    {
        $draft = $this->requireDraft($request);
        $request->merge(['workspace_code' => Workspace::normalizeCode((string) $request->input('workspace_code'))]);

        $data = $request->validate([
            'admin_name' => ['required', 'string', 'min:3', 'max:120'],
            'company_name' => ['required', 'string', 'min:2', 'max:160'],
            'tax_id' => ['nullable', 'string', 'max:20', 'regex:/^[\pL\pN][\pL\pN .\/-]*$/u'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'workspace_code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{3,50}$/'],
            'password' => [$draft->password ? 'nullable' : 'required', 'confirmed', Password::min((int) config('signup.password_min'))->letters()->numbers()],
        ], [
            'tax_id.max' => 'El identificador fiscal admite hasta 20 caracteres.',
            'tax_id.regex' => 'El identificador fiscal solo admite letras, números, espacios, puntos, guiones y barras.',
            'phone.regex' => 'Ingresa un teléfono válido (solo números, espacios, guiones o paréntesis).',
            'workspace_code.regex' => 'Usa de 3 a 50 letras, números, guiones o guion bajo.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ], ['admin_name' => 'nombre del administrador', 'company_name' => 'nombre de la empresa', 'tax_id' => 'identificador fiscal', 'phone' => 'teléfono', 'workspace_code' => 'nombre del Workspace', 'password' => 'contraseña']);

        $this->signup->saveCompany($draft, $data);

        return $this->next($request, 'solution');
    }

    public function solution(Request $request): RedirectResponse
    {
        $draft = $this->requireDraft($request);
        $data = $request->validate(['solution' => ['required', 'string', 'max:20']]);

        $this->signup->chooseSolution($draft, $data['solution']);

        return $this->next($request, 'plan');
    }

    public function plan(Request $request): RedirectResponse
    {
        $draft = $this->requireDraft($request);
        $data = $request->validate(['plan_id' => ['required', 'integer'], 'period' => ['nullable', 'string', Rule::in(array_keys(Plan::PERIODS))]]);
        $key = $draft->solution_key;

        abort_unless($key !== null, 409);
        $this->signup->choosePlan($draft, $key, (int) $data['plan_id'], $data['period'] ?? null);

        return $this->next($request, 'summary');
    }

    /** Confirms the summary: with a provider it goes to the checkout, without one the request waits and says so. */
    public function contract(Request $request): SymfonyResponse
    {
        $draft = $this->requireDraft($request);
        $data = $request->validate(['accepted' => ['required', 'array'], 'accepted.*' => ['string']], ['accepted.required' => 'Debes aceptar los términos y el aviso de privacidad para continuar.']);

        $result = $this->signup->submit($draft, $data['accepted']);

        if ($result['checkoutUrl'] !== null) {
            return Inertia::location($result['checkoutUrl']);
        }

        return $this->next($request, 'payment');
    }

    public function quote(Request $request): RedirectResponse
    {
        $this->signup->requestQuote($this->requireDraft($request));

        return $this->next($request, 'payment');
    }

    public function back(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'in:'.implode(',', self::STEPS)]]);

        return $this->next($request, $data['to']);
    }

    public function cancel(Request $request): RedirectResponse
    {
        if ($draft = $this->draft($request)) {
            $this->signup->cancel($draft);
        }

        $request->session()->forget(['signup.public_id', 'signup.goto']);

        return redirect()->route('pre-login');
    }

    /** The success screen: only for a request whose payment was verified AND whose subscription is really active. Anything else is shown as pending. */
    public function welcome(Request $request): Response|RedirectResponse
    {
        $draft = $this->draft($request);

        if (! $draft || $draft->status !== SignupRequest::COMPLETED || ! $draft->workspace_id
            || Subscription::where('workspace_id', $draft->workspace_id)->value('status') !== Subscription::ACTIVE) {
            return redirect()->route('signup.index');
        }

        $workspace = $draft->workspace()->with('plan')->firstOrFail();

        return Inertia::render('Signup/Welcome', [
            'workspace' => ['name' => $workspace->name, 'code' => $workspace->code],
            'plan' => $this->catalog->plan($draft->plan()->with(['modules' => fn ($q) => $q->where('modules.is_active', true)->orderBy('modules.name')])->firstOrFail()),
            'solution' => $draft->solution_key === SignupCatalog::CUSTOM ? 'Personalizado' : $draft->plan->solution?->name,
            'email' => $draft->email,
        ]);
    }

    // --- helpers -----------------------------------------------------------------------------------------------

    private function draft(Request $request): ?SignupRequest
    {
        return $this->signup->open($request->session()->get('signup.public_id'));
    }

    private function requireDraft(Request $request): SignupRequest
    {
        $draft = $this->draft($request);

        if (! $draft || $draft->status === SignupRequest::COMPLETED) {
            // A missing, expired or finished sign-up session is an expected situation, not an error page: forget the stale state and send the visitor to
            // the wizard (which opens on the step the state allows) with a clear message.
            $request->session()->forget(['signup.public_id', 'signup.goto']);

            throw new HttpResponseException(redirect()->route('signup.index')->with('error', 'Tu sesión de registro venció o no se pudo continuar. Empieza de nuevo.'));
        }

        return $draft;
    }

    private function next(Request $request, string $step): RedirectResponse
    {
        $request->session()->put('signup.goto', $step);

        return redirect()->route('signup.index');
    }

    private function pendingEmail(Request $request): ?string
    {
        $id = $this->catalog->ready() ? $request->session()->get('signup.verification_id') : null;

        return $id ? SignupVerification::whereKey($id)->value('email') : null;
    }

    /** The furthest step the state allows. */
    private function reached(Request $request, ?SignupRequest $draft): string
    {
        if (! $draft) {
            return $request->session()->has('signup.verification_id') && $this->pendingEmail($request) ? 'code' : 'email';
        }

        return match (true) {
            in_array($draft->status, [SignupRequest::AWAITING_PAYMENT, SignupRequest::QUOTE_REQUESTED], true) => 'payment',
            ! $draft->company_name || ! $draft->password => 'company',
            ! $draft->solution_key => 'solution',
            ! $draft->plan_id => 'plan',
            default => 'summary',
        };
    }

    /** @return list<array{key: string, label: string, state: string}> */
    private function progress(string $current): array
    {
        $labels = ['email' => 'Correo', 'company' => 'Empresa', 'solution' => 'Solución', 'plan' => 'Plan', 'summary' => 'Resumen', 'payment' => 'Pago'];
        $position = array_search($current === 'code' ? 'email' : $current, array_keys($labels), true);

        return collect($labels)->keys()->map(fn (string $key, int $i) => [
            'key' => $key, 'label' => $labels[$key], 'state' => $i < $position ? 'done' : ($i === $position ? 'current' : 'todo'),
        ])->all();
    }

    /** @return array<string, mixed> what the wizard may show back (never the password) */
    private function draftProps(SignupRequest $draft): array
    {
        return [
            'adminName' => $draft->admin_name, 'companyName' => $draft->company_name, 'taxId' => $draft->tax_id, 'phone' => $draft->phone,
            'workspaceCode' => $draft->workspace_code, 'hasPassword' => $draft->password !== null, 'status' => $draft->status,
        ];
    }

    /** @return array<string, mixed>|null the plan of the request with its modules and the price snapshot the customer was shown */
    private function selectedPlan(SignupRequest $draft): ?array
    {
        $plan = $this->catalog->offeredPlan((string) $draft->solution_key, $draft->plan_id);

        if (! $plan) {
            return null;
        }

        $shown = $this->catalog->plan($plan);

        // Once the contract was confirmed the customer sees what they ACCEPTED (limits and modules as they were), not what the plan says today.
        if (in_array($draft->status, [SignupRequest::AWAITING_PAYMENT, SignupRequest::QUOTE_REQUESTED], true)) {
            if (is_array($draft->limits_snapshot ?? null)) {
                $shown['limits'] = $this->catalog->limitsOf($draft->limits_snapshot);
            }

            if (is_array($draft->modules_snapshot)) {
                $shown['modules'] = array_values(array_filter($shown['modules'], fn (array $module) => in_array($module['key'], $draft->modules_snapshot, true)));
            }
        }

        return $shown + [
            'solutionName' => $draft->solution_key === SignupCatalog::CUSTOM ? 'Personalizado' : $plan->solution?->name,
            'snapshot' => $draft->price_cents !== null ? ['amount' => $draft->price_cents / 100, 'currency' => $draft->currency, 'period' => $draft->billing_period] : null,
        ];
    }

    /** @return list<array{key: string, title: string, slug: string, version: string}> */
    private function documents(): array
    {
        $legal = app(LegalDocuments::class);

        return collect($this->signup->requiredDocuments())->map(fn (string $version, string $key) => [
            'key' => $key, 'title' => $legal->all()[$key]['title'], 'slug' => $legal->all()[$key]['slug'], 'version' => $version, 'draft' => ! config('legal.reviewed'),
        ])->values()->all();
    }
}
