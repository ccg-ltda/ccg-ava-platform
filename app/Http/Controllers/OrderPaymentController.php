<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Payments\Gateways\GatewayAvailability;
use App\Payments\Gateways\PaymentException;
use App\Payments\Gateways\PaymentLinks;
use App\Payments\Gateways\PaymentService;
use App\Services\LocationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff side of an order's payment: generate the link the customer opens, or revoke the links already given. The Workspace is the session's, the order is looked up INSIDE
 * it, and a link is only offered while the Workspace has at least one gateway it may collect with. The link is shown once (only its hash is stored).
 */
class OrderPaymentController extends Controller
{
    public function __construct(private readonly PaymentLinks $links, private readonly GatewayAvailability $availability) {}

    public function store(Request $request, int $order): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $model = $this->order($request, $order);

        if ($this->availability->offered($workspace, $model->paymentCurrency()) === []) {
            return back()->with('error', 'Este Workspace no tiene ningún método de pago disponible. Configura una pasarela en Integraciones.');
        }

        try {
            $created = $this->links->create($workspace, $model, $request->user());
        } catch (PaymentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('payment_link', route('payments.page', $created['token']))->with('success', 'Enlace de pago generado');
    }

    /** Staff asks the provider again about one attempt of this order (any state, any age). It only asks: it never charges. The attempt is looked up INSIDE this Workspace and this order. */
    public function verify(Request $request, int $order, int $transaction): RedirectResponse
    {
        $model = $this->order($request, $order);
        $attempt = PaymentTransaction::where('workspace_id', $model->workspace_id)->where('payable_type', $model->paymentType())->where('payable_id', $model->id)->findOrFail($transaction);

        $fresh = app(PaymentService::class)->reverify($attempt);

        return back()->with('success', 'Verificado con el proveedor: '.$fresh->state()->label());
    }

    public function destroy(Request $request, int $order): RedirectResponse
    {
        $this->links->revokeFor($request->attributes->get('workspace'), $this->order($request, $order));

        return back()->with('success', 'Enlaces de pago revocados');
    }

    private function order(Request $request, int $order): Order
    {
        $workspace = $request->attributes->get('workspace');
        $model = $workspace->orders()->findOrFail($order);
        app(LocationScope::class)->assertAllows($workspace, $request->user(), $model->location_id);

        return $model;
    }
}
