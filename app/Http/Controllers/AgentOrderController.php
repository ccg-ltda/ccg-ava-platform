<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\Locations;
use App\Http\Requests\AgentCreateOrderRequest;
use App\Models\Conversation;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\Workspace;
use App\Orders\CommerceSchema;
use App\Orders\InventoryService;
use App\Orders\OrderException;
use App\Orders\OrderNotifier;
use App\Orders\OrderService;
use App\Services\AgentConversationContext;
use App\Services\AgentToolTrace;
use App\Services\ModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The order tools of Ava Chat (Delivery and E-commerce), for the automation that runs a chatbot. The assistant never owns an order: it asks Ava to place
 * one and tells the contact what Ava answers. Everything runs through OrderService, so prices, totals, currency, number and state come from the catalog
 * and the server, and what the assistant sends is only which products, how many, how it is delivered and who it is for. A contact can only see the orders
 * of THEIR conversation; the Workspace, the chatbot and the conversation come from the token (see AuthenticateAgent), never from the body.
 * Nothing here moves an order along its flow or cancels it: that is the business's decision (see RECOMENDACIONES.md, «Pedidos desde el asistente»).
 */
class AgentOrderController extends Controller
{
    private const PRODUCT_LIMIT = 50;

    public function __construct(
        private readonly OrderService $orders,
        private readonly CommerceSchema $schema,
        private readonly AgentConversationContext $context,
        private readonly Locations $locations,
        private readonly ModuleAccess $modules,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly AgentToolTrace $trace,
        private readonly InventoryService $inventory,
    ) {}

    /** What the assistant needs before it asks anything: the currency, the delivery methods, the locations and whether the customer will be told of changes. */
    public function options(Request $request): JsonResponse
    {
        return $this->run(fn (Workspace $workspace) => [
            'currency' => $workspace->settingsOrDefault()->currency,
            'methods' => config('orders.methods'),
            'locations' => $this->locations->active($workspace)->map(fn ($location) => ['id' => $location->id, 'name' => $location->name, 'address' => $location->address, 'city' => $location->city])->values()->all(),
            // Today nothing tells the customer when an order changes: the assistant must not promise it.
            'customerNotified' => $this->notifier->available(),
            'delivery' => ['addressRequired' => true, 'coverageChecked' => false, 'shippingCost' => null],
        ], ready: false);
    }

    /** The ACTIVE products with the price Ava has right now (never a remembered one), optionally filtered by text, category or location. */
    public function products(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80'], 'category' => ['nullable', 'string', 'max:80'], 'location_id' => ['nullable', 'integer']]);

        return $this->run(function (Workspace $workspace) use ($request) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim((string) $request->query('q', '')))).'%';
            $location = $request->query('location_id');

            if ($location !== null && $this->locations->enabled($workspace) && ! $workspace->locations()->where('is_active', true)->whereKey((int) $location)->exists()) {
                throw OrderException::unavailable('Esa sede no está disponible.');
            }

            $locationsOn = $this->locations->enabled($workspace);
            $inventory = $this->inventory->ready();
            // The place whose stock answers: the named location, or the general stock of a Workspace without locations. With locations and none named, it is unknown.
            $placeKnown = ! $locationsOn || $location !== null;
            $placeId = $locationsOn && $location !== null ? (int) $location : null;

            $products = $workspace->products()->where('is_active', true)
                ->when(filled($request->query('q')), fn ($q) => $q->where(fn ($w) => $w->whereRaw("lower(name) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(category, '')) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(sku, '')) like ? escape '!'", [$like])))
                ->when(filled($request->query('category')), fn ($q) => $q->where('category', (string) $request->query('category')))
                // A product with no location of its own is offered everywhere; otherwise only where it was assigned (the rule of OrderService).
                ->when($location !== null && $this->locations->enabled($workspace), fn ($q) => $q->where(fn ($w) => $w
                    ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('location_product')->whereColumn('location_product.product_id', 'products.id'))
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('location_product')->whereColumn('location_product.product_id', 'products.id')->where('location_product.location_id', (int) $location))))
                // What cannot be sold in that place is not offered: a product with stock control needs units there (OrderService refuses the order otherwise).
                ->when($inventory && $placeKnown, fn ($q) => $q->where(fn ($w) => $w->where('tracks_inventory', false)->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('inventory_stocks')->whereColumn('inventory_stocks.product_id', 'products.id')->where('inventory_stocks.quantity', '>', 0)
                    ->when($placeId === null, fn ($x) => $x->whereNull('inventory_stocks.location_id'), fn ($x) => $x->where('inventory_stocks.location_id', $placeId)))))
                ->orderBy('name')->limit(self::PRODUCT_LIMIT + 1)->get();
            $shown = $products->take(self::PRODUCT_LIMIT);
            $stocks = $inventory && $placeKnown
                ? InventoryStock::where('workspace_id', $workspace->id)->whereIn('product_id', $shown->pluck('id'))->when($placeId === null, fn ($q) => $q->whereNull('location_id'), fn ($q) => $q->where('location_id', $placeId))->get()->keyBy('product_id')
                : collect();
            $currency = $workspace->settingsOrDefault()->currency;

            return [
                'products' => $shown->map(fn (Product $product) => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'description' => $product->description, 'category' => $product->category, 'price' => number_format((float) $product->price, 2, '.', ''), 'currency' => $currency, 'stock' => $this->stock($product, $stocks->get($product->id), $inventory && $placeKnown)])->values()->all(),
                'truncated' => $products->count() > self::PRODUCT_LIMIT,
                // Whether Ava keeps stock for the products that have stock control (each product says its own state in `stock`). The assistant promises only the `units` it is told.
                'stockTracked' => $inventory,
            ];
        }, ready: false);
    }

    /** What the assistant may say about the stock of a product in the consulted place. @return array<string, mixed> */
    private function stock(Product $product, ?InventoryStock $stock, bool $placeKnown): array
    {
        return match (true) {
            ! $product->tracks_inventory || ! $this->inventory->ready() => ['controlled' => false],
            ! $placeKnown => ['controlled' => true, 'state' => 'location_required'],
            default => ['controlled' => true, 'state' => $stock?->level() ?? InventoryStock::OUT, 'units' => $stock?->quantity ?? 0],
        };
    }

    /** The orders of THIS conversation (most recent first), with the state Ava has right now. */
    public function index(Request $request): JsonResponse
    {
        $contact = $this->context->contact($request);

        return $this->run(function (Workspace $workspace) use ($request, $contact) {
            $conversation = $this->conversation($request, $contact);
            $number = ctype_digit((string) $request->query('number')) ? (int) $request->query('number') : null;

            return ['orders' => $workspace->orders()->with('lines')->where('conversation_id', $conversation->id)->when($number !== null, fn ($q) => $q->where('number', $number))->orderByDesc('number')->limit(20)->get()->map(fn (Order $order) => $this->answer($order))->all()];
        });
    }

    /** Places the order. Repeating the same request (same execution or `Idempotency-Key`, same content) returns the order that already exists (200). */
    public function store(AgentCreateOrderRequest $request): JsonResponse
    {
        $contact = $this->context->contact($request);
        $status = 201;

        return $this->run(function (Workspace $workspace) use ($request, $contact, &$status) {
            $conversation = $this->conversation($request, $contact);
            $idempotency = $request->idempotency($conversation->id)
                ?? throw new OrderException('Falta la clave de idempotencia: envía la cabecera Idempotency-Key (o usa el token de la ejecución) para que un reintento no duplique el pedido.', 422, 'idempotency_key_required');

            // Written before anything changes: if the process dies after the order is saved, the trace can still be settled against it.
            $this->trace->note(['idempotency_ref' => $idempotency['key'], 'conversation_id' => $conversation->id]);

            $data = $request->order();
            $data['customer_id'] = $this->knownCustomer($workspace, $conversation);
            $order = $this->orders->create($workspace, $data, null, ['conversation_id' => $conversation->id] + $idempotency);
            $status = $order->wasRecentlyCreated ? 201 : 200;

            if ($order->wasRecentlyCreated) {
                $this->audit->system('created', 'order', $order->id, $order->auditLabel(), [AuditLogger::change('Total', null, "{$order->total} {$order->currency}"), AuditLogger::change('Origen', null, 'Asistente (Ava Chat)')], $workspace, 'Asistente (automático)');
            }

            // What the trace may say, set only now that the order exists: the order, whether this was a retry, and that nothing was sent to the customer.
            $this->trace->report(['resource_type' => 'order', 'resource_id' => $order->id, 'replayed' => ! $order->wasRecentlyCreated, 'result_state' => $order->status, 'notification' => $this->notifier->available() ? 'planned' : 'unavailable']);

            return ['order' => $this->answer($order->load('lines')), 'replayed' => ! $order->wasRecentlyCreated, 'customerNotified' => $this->notifier->available()];
        }, function () use (&$status) {
            return $status;
        });
    }

    /** The Clientes record of the conversation's contact, when the plan has Clientes and the contact is already linked to one (nothing is created or merged here). */
    private function knownCustomer(Workspace $workspace, Conversation $conversation): ?int
    {
        if (! $this->modules->allows($workspace, 'customers')) {
            return null;
        }

        return $workspace->customers()->where('is_active', true)->whereHas('identities', fn ($q) => $q->where('channel', $conversation->channel)->where('external_id', $conversation->contact_id))->value('id');
    }

    /** The conversation of THIS chatbot with that contact; without it there is nothing an order can be tied to. */
    private function conversation(Request $request, array $contact): Conversation
    {
        return $this->context->conversation($request, $contact) ?? throw new OrderException('No existe una conversación de este asistente con ese contacto. Reporta primero su mensaje a Ava.', 404, 'not_found');
    }

    /** What the assistant may tell the contact about an order: the state Ava really has, the lines as they were bought, and the total Ava computed. @return array<string, mixed> */
    private function answer(Order $order): array
    {
        return [
            'number' => $order->number,
            'status' => $order->status,
            'statusLabel' => config("orders.statuses.{$order->status}"),
            'method' => $order->delivery_method,
            'methodLabel' => config("orders.methods.{$order->delivery_method}"),
            'deliveryAddress' => $order->delivery_address,
            'deliveryCity' => $order->delivery_city,
            'location' => $order->location_name,
            'currency' => $order->currency,
            'total' => number_format((float) $order->total, 2, '.', ''),
            'lines' => $order->lines->map(fn ($line) => ['product' => $line->product_name, 'quantity' => $line->quantity, 'unitPrice' => number_format((float) $line->unit_price, 2, '.', ''), 'lineTotal' => number_format((float) $line->line_total, 2, '.', '')])->all(),
            'createdAt' => $order->created_at?->toIso8601String(),
            'isFinal' => $order->isFinal(),
        ];
    }

    private function run(callable $action, int|\Closure $status = 200, bool $ready = true): JsonResponse
    {
        try {
            // Reading the catalog works as soon as the catalog exists; placing and reading the orders of a conversation needs the link to conversations.
            if (! ($ready ? $this->schema->conversationLinkReady() : $this->schema->ready())) {
                throw new OrderException('Los pedidos del asistente todavía no están disponibles en este entorno: falta aplicar una migración.', 503, 'not_ready');
            }

            return response()->json($action(request()->attributes->get('workspace')), is_int($status) ? $status : $status())->header('Cache-Control', 'no-store');
        } catch (OrderException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->status);
        }
    }
}
