<?php

namespace App\Http\Controllers;

use App\Payments\Gateways\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where a provider sends the customer's browser back (return and cancel). Neither URL proves anything: the opaque token only relates the visit to ONE transaction, the visit
 * is recorded as a hint, and the payment state is then asked to the provider from the server. Whatever the browser sent (query, form fields) is ignored. Unknown tokens are 404.
 */
class PaymentReturnController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function return(Request $request, string $token): RedirectResponse
    {
        return $this->handle($request, $token, 'return');
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        return $this->handle($request, $token, 'cancel');
    }

    private function handle(Request $request, string $token, string $signal): RedirectResponse
    {
        // `id` is the provider's own transaction id when the provider puts it in the URL (Wompi does). It is a CANDIDATE to look up, never believed (see PaymentService::browserSignal).
        $hint = $request->query('id');

        abort_unless($this->payments->browserSignal($token, $signal, is_string($hint) ? $hint : null), 404);

        return redirect()->route('payments.status', $token);
    }
}
