<?php

namespace App\Http\Controllers;

use App\Booking\Locations;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\Workspace;
use App\Orders\CommerceSchema;
use App\Orders\OrderException;
use App\Orders\OrderNotifier;
use App\Orders\OrderService;
use App\Services\ListPagination;
use App\Services\ModuleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedidos of E-commerce and Delivery. Reading is `view-orders` (and `view-order-details` for one order); placing, editing and cancelling an order are `create-orders`, `edit-orders` and `cancel-orders`; moving it
 * along the flow (confirm, prepare, ready, dispatch, deliver) is `process-orders`. Everything starts from the Workspace of the session, and every write goes
 * through OrderService (which owns the prices, the total, the number and the allowed moves). An order is registered, not necessarily paid: Ava does not manage payments.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly CommerceSchema $schema, private readonly Locations $locations, private readonly ModuleAccess $modules, private readonly OrderNotifier $notifier) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => array_key_exists((string) $request->query('status'), config('orders.statuses')) ? (string) $request->query('status') : '',
            'method' => array_key_exists((string) $request->query('method'), config('orders.methods')) ? (string) $request->query('method') : '',
        ];
        $base = ['statuses' => config('orders.statuses'), 'methods' => config('orders.methods'), 'currency' => $workspace->settingsOrDefault()->currency, 'notifier' => $this->notifier->available()];

        if (! $this->schema->ready()) {
            return Inertia::render('Orders/Index', $base + [
                'schemaPending' => true, 'orders' => ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => ListPagination::OPTIONS[0], 'total' => 0, 'from' => 0, 'to' => 0]],
                'filters' => $filters + ['perPage' => ListPagination::OPTIONS[0]], 'perPageOptions' => ListPagination::OPTIONS, 'counts' => [], 'form' => null,
            ]);
        }

        $search = ltrim($filters['search'], '#');
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
        $query = $workspace->orders()
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("lower(customer_name) like ? escape '!'", [$like])->when(ctype_digit($search), fn ($n) => $n->orWhere('number', (int) $search))))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['method'] !== '', fn ($q) => $q->where('delivery_method', $filters['method']))
            ->orderByDesc('number');
        $page = ListPagination::paginate($query, $request, 'orders');

        return Inertia::render('Orders/Index', $base + [
            'schemaPending' => false,
            'orders' => ['data' => $page->getCollection()->map(fn (Order $order) => $this->summary($order))->values()->all(), 'meta' => ListPagination::meta($page)],
            'filters' => $filters + ['perPage' => ListPagination::size($request, 'orders')],
            'perPageOptions' => ListPagination::OPTIONS,
            'counts' => $workspace->orders()->selectRaw('status, count(*) as orders_count')->groupBy('status')->pluck('orders_count', 'status')->map(fn ($count) => (int) $count)->all(),
            'form' => in_array('create-orders', $request->attributes->get('workspace_permissions', []), true) ? $this->formOptions($request, $workspace) : null,
            'canDetail' => in_array('view-order-details', $request->attributes->get('workspace_permissions', []), true),
        ]);
    }

    public function show(Request $request, int $order): Response
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($this->schema->ready(), 404);
        $model = $workspace->orders()->with(['lines', 'statusChanges.user:id,name'])->findOrFail($order);
        $permissions = $request->attributes->get('workspace_permissions', []);

        return Inertia::render('Orders/Show', [
            'order' => $this->summary($model) + [
                'customerPhone' => $model->customer_phone, 'customerEmail' => $model->customer_email, 'customerId' => $model->customer_id,
                'address' => $model->delivery_address, 'city' => $model->delivery_city, 'reference' => $model->delivery_reference,
                'recipientName' => $model->recipient_name, 'recipientPhone' => $model->recipient_phone, 'notes' => $model->notes, 'cancelReason' => $model->cancel_reason,
                'lines' => $model->lines->map(fn ($line) => ['id' => $line->id, 'name' => $line->product_name, 'sku' => $line->product_sku, 'unitPrice' => (float) $line->unit_price, 'quantity' => $line->quantity, 'lineTotal' => (float) $line->line_total])->all(),
                'history' => $model->statusChanges->map(fn (OrderStatusChange $change) => ['id' => $change->id, 'from' => $change->from_status, 'to' => $change->to_status, 'by' => $change->user?->name, 'reason' => $change->reason, 'at' => $change->created_at->toIso8601String()])->all(),
            ],
            'moves' => $this->moves($model, $permissions),
            'canEdit' => in_array('edit-orders', $permissions, true) && ! in_array($model->status, ['dispatched', 'delivered', 'cancelled'], true),
            'statuses' => config('orders.statuses'),
            'methods' => config('orders.methods'),
            'notifier' => $this->notifier->available(),
            'customersModule' => in_array('customers', $this->modules->forRequest($request), true) && in_array('view-customers', $permissions, true),
        ]);
    }

    public function store(CreateOrderRequest $request): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        try {
            $order = $this->orders->create($request->attributes->get('workspace'), $request->order(), $request->user());
        } catch (OrderException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()->route('orders.show', $order->id)->with('success', "Pedido #{$order->number} registrado");
    }

    public function update(UpdateOrderRequest $request, int $order): RedirectResponse
    {
        $model = $request->attributes->get('workspace')->orders()->findOrFail($order);

        try {
            $this->orders->updateDetails($model, $request->validated());
        } catch (OrderException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pedido actualizado');
    }

    /** One move of the flow. `cancelled` needs `cancel-orders` and a written reason; every other move needs `process-orders`. */
    public function transition(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:'.implode(',', array_keys(config('orders.statuses')))], 'reason' => ['nullable', 'required_if:status,cancelled', 'string', 'max:'.config('orders.limits.reason')]], [], ['reason' => 'motivo']);
        $model = $request->attributes->get('workspace')->orders()->findOrFail($order);
        $permissions = $request->attributes->get('workspace_permissions', []);
        $needed = $data['status'] === 'cancelled' ? 'cancel-orders' : 'process-orders';

        abort_unless(in_array($needed, $permissions, true), 403);

        try {
            $moved = $this->orders->transition($model, $data['status'], $request->user(), $data['reason'] ?? null);
        } catch (OrderException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Pedido '.mb_strtolower(config("orders.statuses.{$moved->status}")));
    }

    private function refuseWithoutSchema(): ?RedirectResponse
    {
        return $this->schema->ready() ? null : back()->with('error', 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
    }

    /** The moves this person may make right now on this order (the server checks them again when one is requested). @return list<array{status: string, label: string}> */
    private function moves(Order $order, array $permissions): array
    {
        return collect(config("orders.transitions.{$order->status}", []))
            ->filter(fn (string $to) => ! (($needed = config("orders.method_moves.{$order->status}>{$to}")) && $needed !== $order->delivery_method))
            ->filter(fn (string $to) => in_array($to === 'cancelled' ? 'cancel-orders' : 'process-orders', $permissions, true))
            ->map(fn (string $to) => ['status' => $to, 'label' => config("orders.statuses.{$to}")])->values()->all();
    }

    /** What the "new order" form may choose from: the active catalog, the locations and (only for who may read them) the customers. @return array<string, mixed> */
    private function formOptions(Request $request, Workspace $workspace): array
    {
        $canCustomers = in_array('customers', $this->modules->forRequest($request), true) && in_array('view-customers', $request->attributes->get('workspace_permissions', []), true);

        return [
            'products' => $workspace->products()->with('locations:id')->where('is_active', true)->orderBy('name')->limit(500)->get()->map(fn ($product) => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'price' => (float) $product->price, 'locationIds' => $product->locations->pluck('id')->all()])->all(),
            'locations' => $this->locations->active($workspace)->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])->values()->all(),
            'customers' => $canCustomers ? $workspace->customers()->where('is_active', true)->orderBy('first_name')->orderBy('last_name')->limit(300)->get()->map(fn ($customer) => ['id' => $customer->id, 'name' => $customer->fullName(), 'phone' => $customer->phone, 'email' => $customer->email])->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Order $order): array
    {
        return [
            'id' => $order->id, 'number' => $order->number, 'customerName' => $order->customer_name, 'total' => (float) $order->total, 'currency' => $order->currency,
            'status' => $order->status, 'statusLabel' => config("orders.statuses.{$order->status}"), 'method' => $order->delivery_method, 'methodLabel' => config("orders.methods.{$order->delivery_method}"),
            'location' => $order->location_name, 'createdAt' => $order->created_at->toIso8601String(), 'statusChangedAt' => $order->status_changed_at?->toIso8601String(), 'final' => $order->isFinal(),
        ];
    }
}
