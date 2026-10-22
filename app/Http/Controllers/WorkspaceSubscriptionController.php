<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Free trials and frozen conditions of ONE client Workspace. Only the platform administration reaches it (`workspace.platform:assign-plans`): a client never
 * grants itself a trial. The Workspace comes from the route and the service re-checks every rule (not administrative, no live subscription, valid plan and
 * dates); nothing global changes and no other Workspace is touched. Every operation is audited with its reason.
 */
class WorkspaceSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** Grants a trial to this Workspace from now until `ends_at` (end of that day, in the application's time zone). */
    public function grantTrial(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'starts_at' => ['nullable', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.required' => 'Escribe el motivo de la prueba (al menos 5 caracteres).', 'ends_at.date_format' => 'Indica la fecha de fin con el formato año-mes-día.', 'starts_at.date_format' => 'Indica la fecha de inicio con el formato año-mes-día.']);

        // Dates are days in the application's time zone: a trial starts at 00:00 of its first day and ends at the END of its last day. Without a start date, or
        // with today's, it starts now; a later day SCHEDULES it (no access from it until then).
        $startsAt = filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at'])->startOfDay() : null;

        $subscription = $this->subscriptions->grantTrial($workspace, Plan::findOrFail($data['plan_id']), Carbon::parse($data['ends_at'])->endOfDay(), $data['reason'], $request->user()->id, $startsAt);

        return back()->with('success', $subscription->status === Subscription::SCHEDULED ? 'Prueba gratuita programada correctamente' : 'Prueba gratuita concedida correctamente');
    }

    public function updateTrial(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'ends_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.required' => 'Escribe el motivo del cambio (al menos 5 caracteres).']);

        $this->subscriptions->updateTrial($this->subscription($workspace), Carbon::parse($data['ends_at'])->endOfDay(), $data['reason']);

        return back()->with('success', 'Prueba gratuita actualizada correctamente');
    }

    public function cancelTrial(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Escribe el motivo de la cancelación (al menos 5 caracteres).']);

        $this->subscriptions->cancelTrial($this->subscription($workspace), $data['reason']);

        return back()->with('success', 'Prueba gratuita cancelada');
    }

    /** Freezes, on purpose, today's conditions of the plan for a subscription whose own were never recorded (historical). */
    public function freezeTerms(Workspace $workspace): RedirectResponse
    {
        $this->subscriptions->freezeCurrentTerms($this->subscription($workspace));

        return back()->with('success', 'Condiciones congeladas correctamente');
    }

    /** Records the price agreed outside the public sign-up (D-CON1) on the frozen conditions, with its origin and reason. */
    public function recordAgreement(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000000'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'period' => ['required', Rule::in(['monthly', 'yearly'])],
            'origin' => ['required', Rule::in(array_keys(SubscriptionService::AGREEMENT_ORIGINS))],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.required' => 'Escribe el motivo del acuerdo (al menos 5 caracteres).', 'amount.gt' => 'El importe acordado debe ser mayor que cero.', 'currency.regex' => 'La moneda debe ser un código de tres letras, como COP.']);

        $this->subscriptions->recordAgreement(
            $this->subscription($workspace),
            ['amount_cents' => (int) round(((float) $data['amount']) * 100), 'currency' => $data['currency'], 'period' => $data['period']],
            $data['origin'], $data['reason'], $request->user()->id,
        );

        return back()->with('success', 'Acuerdo comercial registrado correctamente');
    }

    private function subscription(Workspace $workspace): Subscription
    {
        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['workspace_id' => 'El Workspace administrativo no tiene suscripción.']);
        }

        return Subscription::where('workspace_id', $workspace->id)->first()
            ?? throw ValidationException::withMessages(['trial' => 'Este Workspace no tiene una suscripción registrada.']);
    }
}
