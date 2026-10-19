<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\Locations;
use App\Http\Requests\SaveProductRequest;
use App\Models\Product;
use App\Orders\CommerceSchema;
use App\Services\ListPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de productos: what the Workspace sells (E-commerce and Delivery). Never deleted, only deactivated: an inactive product is no longer offered for
 * new orders, and the orders that used it keep their own copy of its name, sku and price. Reading is `view-catalog`; `create-products`, `edit-products` and `activate-products` change it.
 */
class ProductController extends Controller
{
    public function __construct(private readonly CommerceSchema $schema, private readonly Locations $locations, private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $ready = $this->schema->ready();
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => trim((string) $request->query('category', '')),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
        ];
        $empty = ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => ListPagination::OPTIONS[0], 'total' => 0, 'from' => 0, 'to' => 0]];

        if (! $ready) {
            return Inertia::render('Catalog/Index', ['schemaPending' => true, 'products' => $empty, 'filters' => $filters + ['perPage' => ListPagination::OPTIONS[0]], 'perPageOptions' => ListPagination::OPTIONS, 'categories' => [], 'locations' => [], 'currency' => $workspace->settingsOrDefault()->currency, 'total' => 0]);
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
        $query = $workspace->products()->with('locations:id')
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("lower(name) like ? escape '!'", [$like])->orWhereRaw("lower(coalesce(sku, '')) like ? escape '!'", [$like])))
            ->when($filters['category'] !== '', fn ($q) => $q->where('category', $filters['category']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->orderByDesc('is_active')->orderBy('name')->orderBy('id');
        $page = ListPagination::paginate($query, $request, 'products');

        return Inertia::render('Catalog/Index', [
            'schemaPending' => false,
            'products' => ['data' => $page->getCollection()->map(fn (Product $product) => $this->summary($product))->values()->all(), 'meta' => ListPagination::meta($page)],
            'filters' => $filters + ['perPage' => ListPagination::size($request, 'products')],
            'perPageOptions' => ListPagination::OPTIONS,
            'categories' => $workspace->products()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all(),
            'locations' => $this->locations->active($workspace)->map(fn ($location) => ['id' => $location->id, 'name' => $location->name])->values()->all(),
            'currency' => $workspace->settingsOrDefault()->currency,
            'total' => $workspace->products()->count(),
        ]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $workspace = $request->attributes->get('workspace');
        $product = $workspace->products()->create($request->product());
        $this->syncLocations($product, $request->locationIds());

        return back()->with('success', 'Producto creado correctamente');
    }

    public function update(SaveProductRequest $request, int $product): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $request->attributes->get('workspace')->products()->findOrFail($product);
        $model->update($request->product());
        $this->syncLocations($model, $request->locationIds());

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

    private function toggle(Request $request, int $product, bool $active): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $request->attributes->get('workspace')->products()->findOrFail($product);
        $model->update(['is_active' => $active]);

        return back()->with('success', $active ? 'Producto activado correctamente' : 'Producto desactivado correctamente')
            ->with('warning', $active ? null : 'Ya no se ofrece para pedidos nuevos. Los pedidos que ya lo incluyen no cambian.');
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

    /** @return array<string, mixed> */
    private function summary(Product $product): array
    {
        return [
            'id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'description' => $product->description, 'category' => $product->category,
            'price' => (float) $product->price, 'isActive' => $product->is_active, 'locationIds' => $product->locations->pluck('id')->all(),
        ];
    }
}
