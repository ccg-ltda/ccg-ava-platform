<?php

namespace App\Http\Controllers;

use App\Models\PaymentLink;
use App\Models\PaymentTransaction;
use App\Models\Workspace;
use App\Payments\Gateways\GatewayAvailability;
use App\Payments\Gateways\Payable;
use App\Payments\Gateways\PaymentException;
use App\Payments\Gateways\PaymentLinks;
use App\Payments\Gateways\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a customer opens to pay (no session). The opaque token in the URL names ONE payable of ONE Workspace and nothing else: the amount, the currency, the
 * Workspace and the methods offered are all decided here on the server. Unknown, expired and revoked links look the same (404).
 */
class PaymentPageController extends Controller
{
    public function __construct(private readonly PaymentLinks $links, private readonly PaymentService $payments, private readonly GatewayAvailability $availability) {}

    public function show(string $token): Response
    {
        [$link, $payable, $workspace] = $this->resolve($token);
        $summary = $this->payments->summary($workspace->id, $payable->paymentType(), $payable->paymentId());

        return Inertia::render('Pay/Show', [
            'business' => $workspace->name,
            'primaryColor' => $workspace->settingsOrDefault()->primary_color,
            'payment' => [
                'description' => $payable->paymentDescription(), 'amountMinor' => $payable->paymentAmountMinor(), 'currency' => $payable->paymentCurrency(),
                'state' => $summary['state'], 'stateLabel' => $summary['label'],
            ],
            'payable' => $payable->paymentOpen(),
            'gateways' => $summary['state'] === 'unpaid' || $summary['state'] === 'pending' ? array_map(fn (array $gateway) => ['key' => $gateway['key'], 'label' => $gateway['label']], $this->availability->offered($workspace, $payable->paymentCurrency())) : [],
            'attemptKey' => (string) Str::uuid(),
            'startUrl' => route('payments.start', $token),
            'errors' => session('payment_error') ? ['payment' => session('payment_error')] : (object) [],
        ]);
    }

    /** Creates (or reuses) the collection and sends the customer to the provider. */
    public function start(Request $request, string $token): Response|RedirectResponse
    {
        [$link, $payable, $workspace] = $this->resolve($token);
        $data = $request->validate(['gateway' => ['required', 'string', 'max:20'], 'attempt' => ['required', 'string', 'max:64']]);

        try {
            $result = $this->payments->checkout($workspace, $payable, $data['gateway'], $link->id.'|'.$data['attempt'], $link->id);
        } catch (PaymentException $exception) {
            return redirect()->route('payments.page', $token)->with('payment_error', $exception->getMessage());
        }

        if ($result->checkout->method === 'redirect') {
            return Inertia::location($result->checkout->url);
        }

        return Inertia::render('Pay/Redirect', ['action' => $result->checkout->url, 'fields' => (object) $result->checkout->fields, 'business' => $workspace->name, 'primaryColor' => $workspace->settingsOrDefault()->primary_color]);
    }

    /** The result page: what the SERVER knows about the payment, by the transaction's own token (the one the provider returned the customer to). */
    public function status(string $token): Response
    {
        $transaction = strlen($token) === 40 ? PaymentTransaction::where('callback_token_hash', hash('sha256', $token))->first() : null;
        abort_unless($transaction, 404);

        $workspace = Workspace::available()->findOrFail($transaction->workspace_id);
        $state = $transaction->state();

        return Inertia::render('Pay/Status', [
            'business' => $workspace->name,
            'primaryColor' => $workspace->settingsOrDefault()->primary_color,
            'payment' => ['reference' => $transaction->reference, 'amountMinor' => $transaction->amount_minor, 'currency' => $transaction->currency, 'state' => $state->value, 'stateLabel' => $state->label(), 'open' => $state->isOpen()],
        ]);
    }

    /** @return array{0: PaymentLink, 1: Payable, 2: Workspace} */
    private function resolve(string $token): array
    {
        $link = $this->links->resolve($token);
        $payable = $link ? $this->links->payable($link) : null;
        $workspace = $link ? Workspace::available()->find($link->workspace_id) : null;

        abort_unless($link && $payable && $workspace, 404);

        return [$link, $payable, $workspace];
    }
}
