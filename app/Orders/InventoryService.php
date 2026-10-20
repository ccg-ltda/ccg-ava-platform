<?php

namespace App\Orders;

use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The inventory of the Catálogo, per location. The ONLY code that changes a stock: every change writes the movement that explains it (entry, manual adjustment,
 * sale, reversal of a sale) with the quantity before and after, inside one transaction and under a row lock, so two simultaneous operations take turns and
 * the quantity can never go below zero.
 *
 * Rules (see reglas.md §«Inventario»):
 *  - A product only has stock control when `tracks_inventory` is on; every other product behaves as before and nothing is invented for it.
 *  - The stock belongs to ONE location (NULL = the general stock of a business without locations); stocks are never added up as if they were available in one.
 *  - A sale is discounted ONCE, when the order moves from received to confirmed (the shop accepted the sale). A cancellation after that returns what was
 *    discounted, once. The database refuses a second sale or reversal of the same order and product (partial unique index).
 *  - Orders already placed are never touched when this ships: nothing is discounted for them and no movement is invented.
 */
class InventoryService
{
    public function __construct(private readonly CommerceSchema $schema) {}

    public function ready(): bool
    {
        return $this->schema->inventoryReady();
    }

    /** The stock row of a product in a location, optionally locked for the rest of the transaction. */
    public function stock(Product $product, ?int $locationId, bool $lock = false): ?InventoryStock
    {
        $query = InventoryStock::where('workspace_id', $product->workspace_id)->where('product_id', $product->id)
            ->when($locationId === null, fn ($q) => $q->whereNull('location_id'), fn ($q) => $q->where('location_id', $locationId));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Sets up the stock of a new product in the locations it was created with (the first quantity is a real entry, with its movement).
     *
     * @param  list<array{location_id: ?int, quantity: int, min_quantity: int}>  $rows
     */
    public function initialize(Product $product, array $rows, ?User $actor): void
    {
        DB::transaction(function () use ($product, $rows, $actor) {
            foreach ($rows as $row) {
                $stock = InventoryStock::create(['workspace_id' => $product->workspace_id, 'product_id' => $product->id, 'location_id' => $row['location_id'], 'quantity' => 0, 'min_quantity' => max(0, (int) $row['min_quantity'])]);

                if ((int) $row['quantity'] > 0) {
                    $this->change($stock, InventoryMovement::ENTRY, (int) $row['quantity'], $actor, null, 'Existencia inicial');
                }
            }
        });
    }

    /** Merchandise that came in: adds `$quantity` (> 0). Creates the stock of that location when the product had none there. */
    public function receive(Product $product, ?int $locationId, int $quantity, ?User $actor, ?string $reason = null): InventoryStock
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'La entrada debe ser de al menos una unidad.']);
        }

        return DB::transaction(function () use ($product, $locationId, $quantity, $actor, $reason) {
            $stock = $this->lockedOrCreate($product, $locationId);
            $this->change($stock, InventoryMovement::ENTRY, $quantity, $actor, null, $reason ?: 'Entrada de mercancía');

            return $stock->refresh();
        });
    }

    /** A manual correction to the quantity that is really there (never negative). The reason is required: it is what makes the change traceable. */
    public function adjust(Product $product, ?int $locationId, int $newQuantity, ?User $actor, string $reason): InventoryStock
    {
        if ($newQuantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'La existencia no puede ser negativa.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo del ajuste.']);
        }

        return DB::transaction(function () use ($product, $locationId, $newQuantity, $actor, $reason) {
            $stock = $this->lockedOrCreate($product, $locationId);

            if ($newQuantity === $stock->quantity) {
                throw ValidationException::withMessages(['quantity' => 'La existencia ya es esa: no hay nada que ajustar.']);
            }

            $this->change($stock, InventoryMovement::ADJUSTMENT, $newQuantity - $stock->quantity, $actor, null, trim($reason));

            return $stock->refresh();
        });
    }

    /** The alert threshold of one location. It is a setting, not a movement: the quantity does not change. */
    public function setMinimum(Product $product, ?int $locationId, int $minimum): InventoryStock
    {
        if ($minimum < 0) {
            throw ValidationException::withMessages(['min_quantity' => 'El stock mínimo no puede ser negativo.']);
        }

        return DB::transaction(function () use ($product, $locationId, $minimum) {
            $stock = $this->lockedOrCreate($product, $locationId);
            $stock->update(['min_quantity' => $minimum]);

            return $stock;
        });
    }

    /**
     * Whether an order could be placed: every line of a product with stock control needs, in the order's location, a stock row with enough units now. The
     * confirmation checks it again under lock (what is checked here can change before the shop accepts the sale).
     *
     * @param  list<array{product: Product, quantity: int}>  $rows
     *
     * @throws OrderException
     */
    public function assertAvailable(array $rows, ?int $locationId, ?string $locationName): void
    {
        foreach ($rows as $row) {
            if (! $row['product']->tracks_inventory) {
                continue;
            }

            $stock = $this->stock($row['product'], $locationId);

            if (! $stock) {
                throw OrderException::unavailable("«{$row['product']->name}» no tiene inventario ".($locationName ? "en la sede «{$locationName}»." : 'general.'));
            }

            if ($stock->quantity < $row['quantity']) {
                throw OrderException::unavailable("No hay existencias suficientes de «{$row['product']->name}»".($locationName ? " en «{$locationName}»" : '').": hay {$stock->quantity} y el pedido pide {$row['quantity']}.");
            }
        }
    }

    /**
     * The sale of an order: discounts, once, the lines of products with stock control from the stock of the order's location. Called inside the transaction that
     * confirms the order (it holds the order lock). A product without stock control is skipped; an insufficient stock refuses the whole confirmation.
     *
     * @throws OrderException
     */
    public function consume(Order $order, ?User $actor): void
    {
        if (! $this->ready()) {
            return;
        }

        foreach ($order->lines()->whereNotNull('product_id')->orderBy('product_id')->get() as $line) {
            $product = Product::where('workspace_id', $order->workspace_id)->find($line->product_id);

            if (! $product?->tracks_inventory) {
                continue;
            }

            if (InventoryMovement::where('order_id', $order->id)->where('product_id', $product->id)->where('type', InventoryMovement::SALE)->exists()) {
                continue; // already discounted: a sale is never discounted twice
            }

            $stock = $this->stock($product, $order->location_id, true);

            if (! $stock) {
                throw OrderException::state("«{$product->name}» no tiene inventario ".($order->location_name ? "en la sede «{$order->location_name}»." : 'general.').' El pedido no se puede confirmar.');
            }

            if ($stock->quantity < $line->quantity) {
                throw OrderException::state("No hay existencias suficientes de «{$product->name}»".($order->location_name ? " en «{$order->location_name}»" : '').": hay {$stock->quantity} y el pedido pide {$line->quantity}. El pedido no se confirmó.");
            }

            $this->change($stock, InventoryMovement::SALE, -$line->quantity, $actor, $order->id, "Pedido #{$order->number}");
        }
    }

    /** A cancellation after the sale was discounted returns exactly what was discounted, once. Called inside the transaction that cancels the order. */
    public function restore(Order $order, ?User $actor): void
    {
        if (! $this->ready()) {
            return;
        }

        $sales = InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovement::SALE)->orderBy('product_id')->get();

        foreach ($sales as $sale) {
            if (InventoryMovement::where('order_id', $order->id)->where('product_id', $sale->product_id)->where('type', InventoryMovement::CANCELLATION)->exists()) {
                continue;
            }

            $product = Product::where('workspace_id', $order->workspace_id)->findOrFail($sale->product_id);
            $stock = $this->lockedOrCreate($product, $sale->location_id);
            $this->change($stock, InventoryMovement::CANCELLATION, -$sale->quantity_change, $actor, $order->id, "Cancelación del pedido #{$order->number}");
        }
    }

    /** The row of that stock locked, created empty when it does not exist yet (an entry or adjustment in a location the product never had). */
    private function lockedOrCreate(Product $product, ?int $locationId): InventoryStock
    {
        $this->assertLocation($product->workspace_id, $locationId);

        return $this->stock($product, $locationId, true) ?? InventoryStock::create(['workspace_id' => $product->workspace_id, 'product_id' => $product->id, 'location_id' => $locationId, 'quantity' => 0, 'min_quantity' => 0]);
    }

    /** The location, when given, belongs to the Workspace of the product (the composite foreign key also refuses another one). */
    private function assertLocation(int $workspaceId, ?int $locationId): void
    {
        if ($locationId !== null && ! Workspace::findOrFail($workspaceId)->locations()->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Esa sede no existe en este Workspace.']);
        }
    }

    /** Applies a signed change to a LOCKED stock and writes its movement. The quantity never goes below zero. */
    private function change(InventoryStock $stock, string $type, int $delta, ?User $actor, ?int $orderId, ?string $reason): void
    {
        $before = (int) $stock->quantity;
        $after = $before + $delta;

        if ($after < 0) {
            throw ValidationException::withMessages(['quantity' => 'La existencia no puede ser negativa.']);
        }

        $stock->forceFill(['quantity' => $after])->save();
        InventoryMovement::create([
            'workspace_id' => $stock->workspace_id, 'product_id' => $stock->product_id, 'location_id' => $stock->location_id, 'type' => $type,
            'quantity_change' => $delta, 'quantity_before' => $before, 'quantity_after' => $after, 'user_id' => $actor?->id, 'order_id' => $orderId, 'reason' => $reason,
        ]);
    }
}
