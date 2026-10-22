<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\Locations;
use App\Http\Requests\SaveProductRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Orders\CatalogInventory;
use App\Orders\CommerceSchema;
use App\Orders\InventoryService;
use App\Orders\ProductCategories;
use App\Services\ListPagination;
use App\Services\LocationScope;
use App\Services\PrivateImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Catálogo de productos and its inventory: what the Workspace sells and how much of it is where. Never deleted, only deactivated: an inactive product is no
 * longer offered for new orders, and the orders that used it keep their own copy of its name, sku and price. Reading is `view-catalog`; `create-products`,
 * `edit-products` and `activate-products` change the product; the stock is read with `view-inventory` and changes only through `adjust-inventory` (entries,
 * adjustments, minimums) or through the orders (a sale and its cancellation), never from the product form.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly CommerceSchema $schema,
        private readonly Locations $locations,
        private readonly AuditLogger $audit,
        private readonly CatalogInventory $catalog,
        private readonly InventoryService $inventory,
        private readonly ProductCategories $categories,
        private readonly LocationScope $scope,
    ) {}

    /** The locations (ids) a product is offered in; empty = offered in every location (historical, never assigned). @return list<int> */
    private function offeredIn(Product $product): array
    {
        return $product->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all();
    }

    /** A limited member may read only products offered in one of their locations. */
    private function assertSees(Request $request, Product $product): void
    {
        abort_unless($this->scope->maySee($request->attributes->get('workspace'), $request->user(), $this->offeredIn($product)), 403, 'Este producto no está disponible en tus sedes.');
    }

    /** A limited member may change only a product that is offered somewhere and ONLY in their locations (changing it changes it everywhere it is offered). */
    private function assertChanges(Request $request, Product $product): void
    {
        abort_unless($this->scope->mayChange($request->attributes->get('workspace'), $request->user(), $this->offeredIn($product)), 403, 'Este producto también se ofrece en sedes a las que no tienes acceso.');
    }

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $ready = $this->schema->ready();
        $inventory = $this->schema->inventoryReady();
        $canInventory = $inventory && $this->can($request, 'view-inventory');
        $place = $this->catalog->place($workspace, $canInventory ? $request->query('place') : '');
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => trim((string) $request->query('category', '')),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'availability' => $canInventory && in_array($request->query('availability'), ['low', 'out', 'ok', 'untracked', 'unconfigured'], true) ? $request->query('availability') : '',
        ];
        $categorySearch = trim((string) $request->query('category_search', ''));
        $empty = ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => ListPagination::OPTIONS[0], 'total' => 0, 'from' => 0, 'to' => 0]];

        if (! $ready) {
            return Inertia::render('Catalog/Index', ['categoryAdmin' => null, 'schemaPending' => true, 'products' => $empty, 'filters' => $filters + ['perPage' => ListPagination::OPTIONS[0], 'place' => ''], 'perPageOptions' => ListPagination::OPTIONS, 'categories' => [], 'locations' => [], 'currency' => $workspace->settingsOrDefault()->currency, 'total' => 0, 'inventory' => ['ready' => false]]);
        }

        $locations = $this->catalog->locations($workspace);
        $page = ListPagination::paginate($this->catalog->query($workspace, $filters, $place)->with($inventory ? ['locations:id', 'stocks'] : ['locations:id']), $request, 'products');
        $openOrders = $this->openOrders($page->getCollection()->pluck('id')->all());

        return Inertia::render('Catalog/Index', [
            'schemaPending' => false,
            'products' => ['data' => $page->getCollection()->map(fn (Product $product) => $this->summary($product, $locations, $place, $canInventory, $openOrders[$product->id] ?? 0))->values()->all(), 'meta' => ListPagination::meta($page)],
            'filters' => $filters + ['perPage' => ListPagination::size($request, 'products'), 'place' => $place['value']],
            'perPageOptions' => ListPagination::OPTIONS,
            'categories' => $inventory ? $this->categories->options($workspace)->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values()->all() : $workspace->products()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all(),
            'locations' => $locations->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])->values()->all(),
            'currency' => $workspace->settingsOrDefault()->currency,
            'total' => $workspace->products()->when(($scoped = $this->scope->allowedIds($workspace, $request->user())) !== null, fn ($q) => $q->whereIn('id', DB::table('location_product')->whereIn('location_id', $scoped)->select('product_id')))->count(),
            'inventory' => [
                'ready' => $inventory, 'canView' => $canInventory, 'canAdjust' => $inventory && $this->can($request, 'adjust-inventory'),
                'summary' => $canInventory ? $this->catalog->summary($workspace, $place) : null,
                'place' => $place['value'], 'placeLabel' => $place['general'] ? 'Inventario general' : ($place['id'] ? $locations->firstWhere('id', $place['id'])->name : 'Todas las sedes'),
                'config' => ['maxKb' => config('orders.image.max_kb')],
            ],
            // The administration tab: every category (active or not) with the products that use it, and what this person may do with them.
            'categoryAdmin' => $inventory ? [
                'search' => $categorySearch,
                'data' => $this->categories->administration($workspace, $categorySearch)->map(fn ($category) => ['id' => $category->id, 'name' => $category->name, 'isActive' => $category->is_active, 'products' => $category->products_count])->values()->all(),
                'canCreate' => $this->can($request, 'create-products') || $this->can($request, 'edit-products'),
                'canEdit' => $this->can($request, 'edit-products'),
            ] : null,
        ]);
    }

    /** One product: its picture, its data, the stock of each location and — with `view-inventory` — the history of its movements. */
    public function show(Request $request, int $product): Response
    {
        $workspace = $request->attributes->get('workspace');
        $inventory = $this->schema->inventoryReady();
        $model = $workspace->products()->with($inventory ? ['locations:id', 'stocks'] : ['locations:id'])->findOrFail($product);
        $this->assertSees($request, $model);
        $locations = $this->catalog->locations($workspace);
        $canInventory = $inventory && $this->can($request, 'view-inventory');
        $place = ['value' => '', 'id' => null, 'general' => false, 'scope' => $this->scope->allowedIds($workspace, $request->user())];
        $movements = null;
        $movementPlace = '';

        if ($canInventory) {
            // The history of one location when asked (`movement_place` = its id, or `general`); anything else is the whole history of the product.
            $movementPlace = (string) $request->query('movement_place', '');
            $movementPlace = $movementPlace === CatalogInventory::GENERAL || ($movementPlace !== '' && ctype_digit($movementPlace) && $locations->contains('id', (int) $movementPlace)) ? $movementPlace : '';
            $page = ListPagination::paginate(InventoryMovement::where('workspace_id', $workspace->id)->where('product_id', $model->id)
                ->when($movementPlace === CatalogInventory::GENERAL, fn ($q) => $q->whereNull('location_id'))->when(ctype_digit($movementPlace), fn ($q) => $q->where('location_id', (int) $movementPlace))
                ->with(['user:id,name', 'order:id,number'])->orderByDesc('id'), $request, 'movements');
            $movements = ['data' => $page->getCollection()->map(fn (InventoryMovement $movement) => $this->catalog->movementRow($movement, $locations))->values()->all(), 'meta' => ListPagination::meta($page)];
        }

        return Inertia::render('Catalog/Show', [
            'product' => array_merge($this->summary($model, $locations, $place, $canInventory, $this->openOrders([$model->id])[$model->id] ?? 0), ['cost' => $inventory && $model->cost !== null && $this->can($request, 'edit-products') ? (float) $model->cost : null]),
            'locations' => $locations->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])->values()->all(),
            'movements' => $movements,
            'movementPlace' => $movementPlace,
            'categories' => $inventory ? $this->categories->options($workspace)->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->values()->all() : [],
            'perPageOptions' => ListPagination::OPTIONS,
            'currency' => $workspace->settingsOrDefault()->currency,
            'inventory' => ['ready' => $inventory, 'canView' => $canInventory, 'canAdjust' => $inventory && $this->can($request, 'adjust-inventory'), 'config' => ['maxKb' => config('orders.image.max_kb')]],
            'can' => ['edit' => $this->can($request, 'edit-products'), 'activate' => $this->can($request, 'activate-products')],
        ]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $workspace = $request->attributes->get('workspace');
        $control = $this->inventoryControl($request, true);
        $product = DB::transaction(function () use ($request, $workspace, $control) {
            $product = $workspace->products()->create($request->product() + $control['attributes']);
            $this->syncLocations($product, $request->locationIds());

            if ($control['initialize']) {
                $this->inventory->initialize($product, $request->stocks(), $request->user());
            }

            return $product;
        });
        $this->storePicture($product, $request);

        return back()->with('success', 'Producto creado correctamente');
    }

    public function update(SaveProductRequest $request, int $product): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $request->attributes->get('workspace')->products()->findOrFail($product);
        $this->assertChanges($request, $model);
        $control = $this->inventoryControl($request, false);
        $model->update($request->product() + $control['attributes']);
        $this->syncLocations($model, $request->locationIds());
        $this->storePicture($model, $request);

        return back()->with('success', 'Producto actualizado correctamente');
    }

    public function activate(Request $request, int $product): RedirectResponse
    {
        return $this->toggle($request, $product, true);
    }

    public function deactivate(Request $request, int $product): RedirectResponse
    {
        return $this->toggle($request, $product, false);
    }

    /** The picture of a product, only to the members of its Workspace (the bucket stays private). */
    public function image(Request $request, int $product): HttpResponse|StreamedResponse
    {
        $model = $request->attributes->get('workspace')->products()->findOrFail($product);
        $this->assertSees($request, $model);

        return PrivateImage::response($model->image_path, $request);
    }

    private function toggle(Request $request, int $product, bool $active): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $request->attributes->get('workspace')->products()->findOrFail($product);
        $this->assertChanges($request, $model);
        $model->update(['is_active' => $active]);

        return back()->with('success', $active ? 'Producto activado correctamente' : 'Producto desactivado correctamente')
            ->with('warning', $active ? null : 'Ya no se ofrece para pedidos nuevos. Los pedidos que ya lo incluyen y su inventario no cambian.');
    }

    /**
     * What the form may change about the state and the stock control, only with the permission of each: the state needs `activate-products`, the stock control
     * (turning it on or off and the first stock of a new product) needs `adjust-inventory`. A person without it that sends them gets 403, never a silent change.
     *
     * @return array{attributes: array<string, bool>, initialize: bool}
     */
    private function inventoryControl(SaveProductRequest $request, bool $creating): array
    {
        $attributes = [];
        $active = $request->wants('is_active');
        $tracks = $request->wants('tracks_inventory');
        $current = $creating ? null : $request->attributes->get('workspace')->products()->findOrFail($request->route('product'));

        if ($active !== null && $active !== ($current ? $current->is_active : true)) {
            abort_unless($this->can($request, 'activate-products'), 403);
            $attributes['is_active'] = $active;
        }

        if ($tracks !== null && $tracks !== ($current?->tracks_inventory ?? false)) {
            abort_unless($this->can($request, 'adjust-inventory'), 403);
            $attributes['tracks_inventory'] = $tracks;
        }

        $initialize = $creating && ($attributes['tracks_inventory'] ?? false) && $request->stocks() !== [];
        abort_if($request->stocks() !== [] && ! $creating, 422, 'Las existencias cambian con entradas y ajustes, no desde el formulario del producto.');

        return ['attributes' => $attributes, 'initialize' => $initialize];
    }

    /** Stores a new picture or removes it. The previous file is deleted only after the product points to the new one and only when no other product uses it. */
    private function storePicture(Product $product, SaveProductRequest $request): void
    {
        if (! $this->schema->inventoryReady() || (! $request->picture() && ! $request->boolean('remove_image'))) {
            return;
        }

        $stored = $request->picture() ? Storage::putFile("workspaces/{$product->workspace_id}/products/{$product->id}", $request->picture()) : null;
        $previous = $product->image_path;

        try {
            $product->forceFill(['image_path' => $stored])->save();
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::delete($stored);
            }

            throw $exception;
        }

        if ($previous && $previous !== $stored && ! Product::where('image_path', $previous)->where('id', '!=', $product->id)->exists()) {
            Storage::delete($previous);
        }

        $this->audit->record('updated', 'product', $product->id, $product->name, [AuditLogger::change('Imagen', $previous ? 'Cargada' : null, $stored ? 'Cargada' : 'Quitada')], $product->workspace);
    }

    private function refuseWithoutSchema(): ?RedirectResponse
    {
        return $this->schema->ready() ? null : back()->with('error', 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
    }

    /** The locations that offer a product (none = all of them): the same Workspace is validated and the pivot rows carry it so the database enforces it too. */
    private function syncLocations(Product $product, array $ids): void
    {
        $names = fn () => $product->locations()->orderBy('locations.name')->pluck('locations.name')->implode(', ');
        $before = $names();
        $product->locations()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['workspace_id' => $product->workspace_id]])->all());
        $after = $names();

        if ($before !== $after) {
            $this->audit->record('updated', 'product', $product->id, $product->name, [AuditLogger::change('Sedes que lo ofrecen', $before ?: null, $after ?: null)], $product->workspace);
        }
    }

    private function can(Request $request, string $permission): bool
    {
        return in_array($permission, $request->attributes->get('workspace_permissions', []), true);
    }

    /** How many orders still in motion (received to dispatched) include each product: what deactivating it would leave pending. @param  list<int>  $ids @return array<int, int> */
    private function openOrders(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('order_lines')->join('orders', fn ($join) => $join->on('orders.id', '=', 'order_lines.order_id')->on('orders.workspace_id', '=', 'order_lines.workspace_id'))
            ->whereIn('order_lines.product_id', $ids)->whereNotIn('orders.status', ['delivered', 'cancelled'])
            ->groupBy('order_lines.product_id')->selectRaw('order_lines.product_id, count(distinct orders.id) as total')->pluck('total', 'product_id')->map(fn ($count) => (int) $count)->all();
    }

    /** @return array<string, mixed> */
    private function summary(Product $product, $locations, array $place, bool $canInventory, int $openOrders): array
    {
        $inventory = $this->schema->inventoryReady();

        return [
            'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'description' => $product->description,
            'category' => $product->category, 'categoryId' => $inventory ? $product->category_id : null,
            'price' => (float) $product->price, 'isActive' => $product->is_active, 'locationIds' => $product->locations->pluck('id')->all(),
            // A historical product offered in no location: it still sells as before, but it is flagged until someone assigns it on purpose.
            'needsLocation' => $product->locations->isEmpty() && $locations->isNotEmpty(),
            'imageUrl' => $inventory && $product->image_path ? route('catalog.image', $product->id).'?v='.$product->updated_at?->timestamp : null,
            'tracksInventory' => $inventory && $product->tracks_inventory, 'openOrders' => $openOrders,
            'stock' => $canInventory ? $this->catalog->stockView($product, $locations, $place) : null,
            'cost' => null,
        ];
    }
}
