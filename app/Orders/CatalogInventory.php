<?php

namespace App\Orders;

use App\Booking\Locations;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Catálogo screens read about stock: the sedes that can be consulted, the filters (category, state, availability, search) applied in the database,
 * the summary of each product and the figures of the compact summary. Pure reading: nothing here changes a stock (that is InventoryService).
 *
 * Reading rules: the stock of a location is shown on its own and the total is always labelled as «todas las sedes»; a product without stock control has no
 * quantity at all (it is not «out of stock»); a product without a stock row in a location is NOT available there; a deactivated product is a different
 * condition from an out-of-stock one.
 */
class CatalogInventory
{
    /** The selector value of the general stock (a Workspace without locations). */
    public const GENERAL = 'general';

    public function __construct(private readonly Locations $locations, private readonly CommerceSchema $schema) {}

    /** @return Collection<int, Location> the locations whose stock can be consulted (empty when the Workspace has none: the stock is then the general one) */
    public function locations(Workspace $workspace): Collection
    {
        return $this->locations->active($workspace);
    }

    /**
     * The consulted place from the request: `''` = every location, `general` or the id of an active location. Anything else is «every location».
     *
     * @return array{value: string, id: ?int, general: bool}
     */
    public function place(Workspace $workspace, mixed $requested): array
    {
        $requested = (string) $requested;

        if ($requested === self::GENERAL && $this->locations($workspace)->isEmpty()) {
            return ['value' => self::GENERAL, 'id' => null, 'general' => true];
        }

        if (ctype_digit($requested) && $this->locations($workspace)->contains('id', (int) $requested)) {
            return ['value' => $requested, 'id' => (int) $requested, 'general' => false];
        }

        return ['value' => '', 'id' => null, 'general' => false];
    }

    /** @return Builder<Product> */
    public function query(Workspace $workspace, array $filters, array $place): Builder
    {
        $inventory = $this->schema->inventoryReady();

        return Product::where('workspace_id', $workspace->id)
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
                $q->where(fn ($w) => $w->whereRaw("lower(name) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(sku, '')) like ? escape '!'", [$like]));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($inventory && $filters['category'] !== '', fn ($q) => $q->where('category_id', (int) $filters['category']))
            ->when(! $inventory && $filters['category'] !== '', fn ($q) => $q->where('category', $filters['category']))
            ->when($inventory && $filters['availability'] !== '', fn ($q) => $this->availability($q, $filters['availability'], $place))
            ->orderByDesc('is_active')->orderBy('name')->orderBy('id');
    }

    /** Whether the product has a stock row in the consulted place that satisfies `$extra` (a correlated EXISTS subquery). */
    private function stockExists(array $place, ?callable $extra = null): callable
    {
        return function ($q) use ($place, $extra) {
            $q->select(DB::raw(1))->from('inventory_stocks')->whereColumn('inventory_stocks.product_id', 'products.id')
                ->when($place['general'], fn ($w) => $w->whereNull('inventory_stocks.location_id'))
                ->when($place['id'] !== null, fn ($w) => $w->where('inventory_stocks.location_id', $place['id']));
            $extra && $extra($q);
        };
    }

    /**
     * «unconfigured»: stock control and NO stock row in the consulted place (nothing was ever registered there: it is not zero, it is pending). «out»: stock
     * control and a stock row with nothing on hand (a configured zero). «low»: something on hand but at or below the minimum of that place. «ok»: on hand and
     * above it. «untracked»: no stock control.
     */
    private function availability(Builder $query, string $availability, array $place): Builder
    {
        $configured = $this->stockExists($place);
        $onHand = $this->stockExists($place, fn ($q) => $q->where('inventory_stocks.quantity', '>', 0));
        $low = $this->stockExists($place, fn ($q) => $q->where('inventory_stocks.quantity', '>', 0)->where('inventory_stocks.min_quantity', '>', 0)->whereColumn('inventory_stocks.quantity', '<=', 'inventory_stocks.min_quantity'));

        return match ($availability) {
            'untracked' => $query->where('tracks_inventory', false),
            'unconfigured' => $query->where('tracks_inventory', true)->whereNotExists($configured),
            'out' => $query->where('tracks_inventory', true)->whereExists($configured)->whereNotExists($onHand),
            'low' => $query->where('tracks_inventory', true)->whereExists($low),
            'ok' => $query->where('tracks_inventory', true)->whereExists($onHand)->whereNotExists($low),
            default => $query,
        };
    }

    /**
     * The compact summary above the list, for the consulted place: products with stock control, with low stock, out of stock (a configured zero) and still to
     * configure (control on, no stock row in the place), and the units on hand. The three conditions never overlap: a product without a row is not «out».
     *
     * @return array{tracked: int, low: int, out: int, unconfigured: int, units: int}
     */
    public function summary(Workspace $workspace, array $place): array
    {
        if (! $this->schema->inventoryReady()) {
            return ['tracked' => 0, 'low' => 0, 'out' => 0, 'unconfigured' => 0, 'units' => 0];
        }

        $base = fn () => Product::where('workspace_id', $workspace->id)->where('is_active', true)->where('tracks_inventory', true);
        $units = DB::table('inventory_stocks')->where('workspace_id', $workspace->id)
            ->when($place['general'], fn ($q) => $q->whereNull('location_id'))->when($place['id'] !== null, fn ($q) => $q->where('location_id', $place['id']))
            ->whereIn('product_id', $workspace->products()->where('is_active', true)->where('tracks_inventory', true)->select('id'))->sum('quantity');

        return [
            'tracked' => $base()->count(),
            'low' => $this->availability($base(), 'low', $place)->count(),
            'out' => $this->availability($base(), 'out', $place)->count(),
            'unconfigured' => $this->availability($base(), 'unconfigured', $place)->count(),
            'units' => (int) $units,
        ];
    }

    /**
     * How a product looks in the list. `stocks` holds one entry per place that has a row; `selected` is the consulted place's stock (null for «every location»
     * or when the product has none there); `total` is the sum of the stocks the list is about and says which places it covers.
     *
     * @param  Collection<int, Location>  $locations
     * @return array<string, mixed>
     */
    public function stockView(Product $product, Collection $locations, array $place): array
    {
        if (! $this->schema->inventoryReady() || ! $product->tracks_inventory) {
            return ['tracked' => (bool) ($this->schema->inventoryReady() && $product->tracks_inventory), 'stocks' => [], 'selected' => null, 'total' => null, 'level' => null];
        }

        $rows = $product->stocks->map(fn (InventoryStock $stock) => [
            'locationId' => $stock->location_id,
            'location' => $stock->location_id ? ($locations->firstWhere('id', $stock->location_id)?->name ?? 'Sede inactiva') : 'Inventario general',
            'quantity' => $stock->quantity, 'min' => $stock->min_quantity, 'level' => $stock->level(),
        ])->values();
        $consulted = $rows->filter(fn (array $row) => $place['id'] === null && ! $place['general'] || $row['locationId'] === $place['id']);
        $selected = ($place['id'] !== null || $place['general']) ? $consulted->first() : null;
        $level = match (true) {
            // No row at all is «pending» (no level): only a configured zero is out of stock.
            $consulted->isEmpty() => null,
            $consulted->sum('quantity') <= 0 => InventoryStock::OUT,
            $consulted->contains(fn (array $row) => $row['level'] === InventoryStock::LOW) => InventoryStock::LOW,
            default => InventoryStock::OK,
        };

        return [
            'tracked' => true,
            'stocks' => $rows->all(),
            'selected' => $selected,
            'total' => ['quantity' => $consulted->sum('quantity'), 'places' => $consulted->count(), 'scope' => $place['id'] === null && ! $place['general'] ? 'all' : 'one'],
            'level' => $selected['level'] ?? $level,
            'unavailableHere' => ($place['id'] !== null || $place['general']) && $selected === null,
        ];
    }

    /** The movements of a product, newest first, in the shape the history shows. */
    public function movementRow(InventoryMovement $movement, Collection $locations): array
    {
        return [
            'id' => $movement->id, 'type' => $movement->type, 'typeLabel' => InventoryMovement::LABELS[$movement->type] ?? $movement->type,
            'change' => $movement->quantity_change, 'before' => $movement->quantity_before, 'after' => $movement->quantity_after,
            'location' => $movement->location_id ? ($locations->firstWhere('id', $movement->location_id)?->name ?? 'Sede inactiva') : 'Inventario general',
            'user' => $movement->user?->name, 'orderId' => $movement->order_id, 'orderNumber' => $movement->order?->number, 'reason' => $movement->reason,
            'createdAt' => $movement->created_at?->toIso8601String(),
        ];
    }
}
