<?php

namespace App\Orders;

use App\Booking\Locations;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ModuleAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way an order is written. It never trusts what the browser (or, later, an assistant) says about money: the products, their prices and
 * the total come from the catalog of the Workspace at the moment the order is placed, and are copied into the order. Everything starts from the
 * Workspace it is given and refuses what belongs to another one. An order number is allocated while the Workspace row is locked, so two orders
 * placed together never share a number.
 */
class OrderService
{
    public function __construct(private readonly Locations $locations, private readonly ModuleAccess $modules, private readonly OrderNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $data  customer_name, customer_phone?, customer_email?, customer_id?, delivery_method, delivery_address?, delivery_city?,
     *                                      delivery_reference?, recipient_name?, recipient_phone?, location_id?, notes?, lines: list<array{product_id, quantity}>
     * @param  array{conversation_id: int, key: string, fingerprint: string}|null  $origin  only the assistant's tools pass it: the conversation the order comes from and the digest that identifies this
     *                                                                                      request. Asking again with the same key returns the order that already exists (`wasRecentlyCreated` is false) and tells nobody twice.
     *
     * @throws OrderException
     */
    public function create(Workspace $workspace, array $data, ?User $actor, ?array $origin = null): Order
    {
        $lines = $this->merge($data['lines'] ?? []);
        $location = $this->location($workspace, $data['location_id'] ?? null);
        $customer = $this->customer($workspace, $data['customer_id'] ?? null);
        $method = (string) ($data['delivery_method'] ?? 'pickup');

        if (! array_key_exists($method, config('orders.methods'))) {
            throw OrderException::unavailable('Elige si el pedido se recoge o se entrega.');
        }

        if ($method === 'delivery' && ! filled($data['delivery_address'] ?? null)) {
            throw OrderException::unavailable('Un pedido a domicilio necesita la dirección de entrega.');
        }

        $name = trim((string) ($data['customer_name'] ?? '')) ?: ($customer?->fullName() ?? '');

        if ($name === '') {
            throw OrderException::unavailable('Indica el nombre de quien hace el pedido.');
        }

        $order = DB::transaction(function () use ($workspace, $data, $lines, $location, $customer, $method, $name, $actor, $origin) {
            // One order at a time per Workspace gets its number (and reads the catalog): the lock is on the Workspace row.
            Workspace::whereKey($workspace->id)->lockForUpdate()->first();

            // A retried request from the assistant is the same order, not a second one (the lock makes two simultaneous retries take turns).
            if ($origin && ($existing = $workspace->orders()->where('idempotency_key', $origin['key'])->first())) {
                // The same key with the same content is a retry; the same key with another content is NOT a retry and must not become another order.
                if ($existing->idempotency_fingerprint !== $origin['fingerprint']) {
                    throw new OrderException('Esa clave de idempotencia ya se usó con otro contenido: no se creó ningún pedido. Usa una clave nueva para un pedido distinto.', 409, 'idempotency_conflict');
                }

                return $existing;
            }

            $products = $workspace->products()->whereIn('id', array_keys($lines))->get()->keyBy('id');
            $cents = 0;
            $rows = [];

            foreach ($lines as $productId => $quantity) {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if (! $product || ! $product->is_active) {
                    throw OrderException::unavailable('Uno de los productos ya no está disponible. Actualiza el catálogo y vuelve a intentarlo.');
                }

                if ($location && ! $this->offers($location, $product)) {
                    throw OrderException::unavailable("«{$product->name}» no se ofrece en la sede «{$location->name}».");
                }

                $unit = $this->cents($product->price);
                $cents += $unit * $quantity;
                $rows[] = ['product' => $product, 'quantity' => $quantity, 'unit' => $unit];
            }

            if ($cents > 99_999_999_999_999) { // what a decimal(14,2) column holds
                throw OrderException::unavailable('El total del pedido supera el máximo permitido.');
            }

            $order = $workspace->orders()->create([
                'workspace_id' => $workspace->id,
                'number' => ((int) $workspace->orders()->max('number')) + 1,
                'customer_id' => $customer?->id,
                'customer_name' => $name,
                'customer_phone' => $this->text($data['customer_phone'] ?? $customer?->phone),
                'customer_email' => $this->text($data['customer_email'] ?? $customer?->email),
                'delivery_method' => $method,
                'delivery_address' => $method === 'delivery' ? $this->text($data['delivery_address'] ?? null) : null,
                'delivery_city' => $method === 'delivery' ? $this->text($data['delivery_city'] ?? null) : null,
                'delivery_reference' => $method === 'delivery' ? $this->text($data['delivery_reference'] ?? null) : null,
                'recipient_name' => $method === 'delivery' ? $this->text($data['recipient_name'] ?? null) : null,
                'recipient_phone' => $method === 'delivery' ? $this->text($data['recipient_phone'] ?? null) : null,
                'location_id' => $location?->id,
                'location_name' => $location?->name,
                'currency' => $workspace->settingsOrDefault()->currency,
                'total' => $this->money($cents),
                'status' => 'received',
                'status_changed_at' => now(),
                'notes' => $this->text($data['notes'] ?? null),
            ] + ($origin ? ['conversation_id' => $origin['conversation_id'], 'idempotency_key' => $origin['key'], 'idempotency_fingerprint' => $origin['fingerprint']] : [])); // only the assistant's orders carry them (and only once the migration ran)

            foreach ($rows as $row) {
                OrderLine::create([
                    'workspace_id' => $workspace->id, 'order_id' => $order->id, 'product_id' => $row['product']->id, 'product_name' => $row['product']->name,
                    'product_sku' => $row['product']->sku, 'unit_price' => $this->money($row['unit']), 'quantity' => $row['quantity'], 'line_total' => $this->money($row['unit'] * $row['quantity']),
                ]);
            }

            OrderStatusChange::create(['workspace_id' => $workspace->id, 'order_id' => $order->id, 'from_status' => null, 'to_status' => 'received', 'user_id' => $actor?->id]);

            return $order;
        });

        if ($order->wasRecentlyCreated) {
            $this->tell($order, 'received');
        }

        return $order;
    }

    /**
     * Moves an order along the allowed graph (config/orders.php). `cancelled` needs a reason; a pickup order is never dispatched and a delivery order is never
     * "handed over" without having been dispatched. The order is re-read under a lock, so two people moving it together cannot both succeed.
     *
     * @throws OrderException
     */
    public function transition(Order $order, string $to, ?User $actor, ?string $reason = null): Order
    {
        $statuses = config('orders.statuses');

        if (! array_key_exists($to, $statuses)) {
            throw OrderException::unavailable('Ese estado no existe.');
        }

        $reason = $this->text($reason);

        if ($to === 'cancelled' && $reason === null) {
            throw OrderException::unavailable('Escribe el motivo de la cancelación.');
        }

        $moved = DB::transaction(function () use ($order, $to, $actor, $reason, $statuses) {
            $current = Order::where('workspace_id', $order->workspace_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $current->status;

            if ($from === $to) {
                throw OrderException::state("El pedido ya está «{$statuses[$to]}».");
            }

            if (! in_array($to, config("orders.transitions.{$from}", []), true)) {
                throw OrderException::state("Un pedido «{$statuses[$from]}» no puede pasar a «{$statuses[$to]}».");
            }

            $needed = config("orders.method_moves.{$from}>{$to}");

            if ($needed && $needed !== $current->delivery_method) {
                throw OrderException::state($needed === 'delivery' ? 'Solo un pedido a domicilio se despacha: este se recoge.' : 'Un pedido a domicilio se entrega después de despacharlo.');
            }

            $current->forceFill(['status' => $to, 'status_changed_at' => now(), 'cancel_reason' => $to === 'cancelled' ? $reason : $current->cancel_reason])->save();
            OrderStatusChange::create(['workspace_id' => $current->workspace_id, 'order_id' => $current->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $actor?->id, 'reason' => $reason]);

            return $current;
        });

        $this->tell($moved, 'status_changed');

        return $moved;
    }

    /**
     * Operational details that may still change while the order is in the shop's hands: the delivery data and the notes. Never the lines, the
     * total, the method or the customer: to change those the order is cancelled and placed again.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws OrderException
     */
    public function updateDetails(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $current = Order::where('workspace_id', $order->workspace_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($current->status, ['dispatched', 'delivered', 'cancelled'], true)) {
                throw OrderException::state('Este pedido ya salió o terminó: sus datos de entrega no se pueden cambiar.');
            }

            $changes = ['notes' => $this->text($data['notes'] ?? null)];

            if ($current->delivery_method === 'delivery') {
                $address = $this->text($data['delivery_address'] ?? null);

                if ($address === null) {
                    throw OrderException::unavailable('Un pedido a domicilio necesita la dirección de entrega.');
                }

                $changes += [
                    'delivery_address' => $address, 'delivery_city' => $this->text($data['delivery_city'] ?? null), 'delivery_reference' => $this->text($data['delivery_reference'] ?? null),
                    'recipient_name' => $this->text($data['recipient_name'] ?? null), 'recipient_phone' => $this->text($data['recipient_phone'] ?? null),
                ];
            }

            $current->forceFill($changes)->save();

            return $current;
        });
    }

    // --- helpers ----------------------------------------------------------------------------------------------------------------------

    /** The same product twice is one line; at most `limits.lines` different products and `limits.quantity` of each. @return array<int, int> product id => quantity */
    private function merge(mixed $lines): array
    {
        $merged = [];

        foreach ((array) $lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($id <= 0 || $quantity <= 0) {
                throw OrderException::unavailable('Cada línea necesita un producto y una cantidad mayor que cero.');
            }

            $merged[$id] = ($merged[$id] ?? 0) + $quantity;
        }

        if ($merged === []) {
            throw OrderException::unavailable('Añade al menos un producto al pedido.');
        }

        if (count($merged) > config('orders.limits.lines') || max($merged) > config('orders.limits.quantity')) {
            throw OrderException::unavailable('El pedido supera el máximo de productos o de unidades permitido.');
        }

        return $merged;
    }

    /** The location of an order is optional; when named it must be an ACTIVE one of this Workspace and the plan must include Sedes. */
    private function location(Workspace $workspace, mixed $id): ?Location
    {
        if ($id === null || $id === '') {
            return null;
        }

        $found = $this->locations->enabled($workspace) && (is_int($id) || ctype_digit((string) $id)) ? $workspace->locations()->where('is_active', true)->find((int) $id) : null;

        return $found ?? throw OrderException::unavailable('Esa sede no está disponible.');
    }

    /** The Clientes record of an order is optional; when named the plan must include Clientes and it must be one of this Workspace. */
    private function customer(Workspace $workspace, mixed $id): ?Customer
    {
        if ($id === null || $id === '') {
            return null;
        }

        $found = $this->modules->allows($workspace, 'customers') && (is_int($id) || ctype_digit((string) $id)) ? $workspace->customers()->find((int) $id) : null;

        return $found ?? throw OrderException::unavailable('Ese cliente no está disponible.');
    }

    /** A product with no location of its own is offered everywhere; otherwise only where it was assigned. */
    private function offers(Location $location, Product $product): bool
    {
        $rows = DB::table('location_product')->where('workspace_id', $product->workspace_id)->where('product_id', $product->id);

        return ! $rows->exists() || (clone $rows)->where('location_id', $location->id)->exists();
    }

    /** Whole cents of a decimal price, without floating point surprises. */
    private function cents(mixed $price): int
    {
        return (int) round(((float) $price) * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** Telling the customer is a separate, optional step: a failure there never undoes or blocks the order. */
    private function tell(Order $order, string $event): void
    {
        try {
            $this->notifier->notify($order, $event);
        } catch (Throwable $e) {
            Log::error('order_notification_failed', ['exception' => $e::class, 'order' => $order->id]);
        }
    }
}
