<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\Product;
use App\Orders\CatalogInventory;
use App\Orders\CommerceSchema;
use App\Orders\InventoryService;
use App\Services\LocationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Entries, adjustments and minimums of the stock of a product in ONE location. Every route needs `adjust-inventory`; every quantity change goes through
 * InventoryService (one transaction, a row lock, a movement with the quantity before and after). A product without stock control cannot receive stock: turn the
 * control on first. The location, when sent, must be an active one of the active Workspace; the general stock (no location) only exists in a Workspace without locations.
 */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly CatalogInventory $catalog, private readonly CommerceSchema $schema, private readonly AuditLogger $audit) {}

    public function receive(Request $request, int $product): RedirectResponse
    {
        [$model, $locationId] = $this->target($request, $product);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.config('orders.inventory.max_quantity')], 'reason' => ['nullable', 'string', 'max:255']], [
            'quantity.min' => 'La entrada debe ser de al menos una unidad.',
        ], ['quantity' => 'la cantidad']);

        $this->inventory->receive($model, $locationId, (int) $data['quantity'], $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Entrada registrada correctamente');
    }

    public function adjust(Request $request, int $product): RedirectResponse
    {
        [$model, $locationId] = $this->target($request, $product);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.config('orders.inventory.max_quantity')], 'reason' => ['required', 'string', 'max:255']], [
            'quantity.min' => 'La existencia no puede ser negativa.',
            'reason.required' => 'Escribe el motivo del ajuste.',
        ], ['quantity' => 'la existencia']);

        $this->inventory->adjust($model, $locationId, (int) $data['quantity'], $request->user(), $data['reason']);

        return back()->with('success', 'Ajuste registrado correctamente');
    }

    public function minimum(Request $request, int $product): RedirectResponse
    {
        [$model, $locationId] = $this->target($request, $product);
        $data = $request->validate(['min_quantity' => ['required', 'integer', 'min:0', 'max:'.config('orders.inventory.max_quantity')]], ['min_quantity.min' => 'El stock mínimo no puede ser negativo.'], ['min_quantity' => 'el stock mínimo']);
        $before = $this->inventory->stock($model, $locationId)?->min_quantity;

        $stock = $this->inventory->setMinimum($model, $locationId, (int) $data['min_quantity']);

        if ($before !== $stock->min_quantity) {
            $this->audit->record('updated', 'product', $model->id, $model->name, [AuditLogger::change('Stock mínimo ('.($stock->location?->name ?? 'inventario general').')', $before === null ? null : (string) $before, (string) $stock->min_quantity)], $model->workspace);
        }

        return back()->with('success', 'Stock mínimo guardado correctamente');
    }

    /**
     * Turns the stock control of a product on or off from its management page. Turning it on invents nothing (the stock stays to be registered, location by
     * location); turning it off keeps the stocks and the whole history. Needs `adjust-inventory`, like every change of the inventory.
     */
    public function control(Request $request, int $product): RedirectResponse
    {
        abort_unless($this->schema->inventoryReady(), 409, 'Falta aplicar la última migración de la base de datos.');

        $model = $request->attributes->get('workspace')->products()->findOrFail($product);
        abort_unless(app(LocationScope::class)->mayChange($request->attributes->get('workspace'), $request->user(), $model->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all()), 403, 'Este producto también se ofrece en sedes a las que no tienes acceso.');
        $tracks = $request->validate(['tracks_inventory' => ['required', 'boolean']])['tracks_inventory'];

        if ($model->tracks_inventory === (bool) $tracks) {
            return back();
        }

        $model->update(['tracks_inventory' => (bool) $tracks]);

        return back()->with('success', $tracks ? 'Control de inventario activado' : 'Control de inventario desactivado')
            ->with('warning', $tracks ? 'Registra las existencias iniciales de cada sede: hasta entonces el producto no se puede pedir.' : 'El producto ya no se bloquea por falta de stock. Sus existencias y su historial se conservan.');
    }

    /** The product (of the active Workspace) and the location the request is about. @return array{0: Product, 1: ?int} */
    private function target(Request $request, int $product): array
    {
        abort_unless($this->schema->inventoryReady(), 409, 'Falta aplicar la última migración de la base de datos.');

        $workspace = $request->attributes->get('workspace');
        $model = $workspace->products()->findOrFail($product);
        $places = $this->catalog->locations($workspace);
        $request->validate(['location_id' => ['nullable', 'integer', Rule::in($places->pluck('id')->all())]], ['location_id.in' => 'Elige una sede activa del Workspace.']);
        $locationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $scope = app(LocationScope::class);

        // The general stock (no location) and a product of other locations are not for a member limited to selected locations.
        abort_unless($scope->allows($workspace, $request->user(), $locationId) && $scope->maySee($workspace, $request->user(), $model->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all()), 403, 'No tienes acceso a esa sede o a este producto.');

        if ($locationId === null && $places->isNotEmpty()) {
            abort(422, 'Elige la sede: este Workspace lleva el inventario por sede.');
        }

        // The stock of a product only exists where the product is offered: a location it is not assigned to can not receive, adjust or set a minimum for it.
        $offered = $model->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all();
        abort_if($locationId !== null && $offered !== [] && ! in_array($locationId, $offered, true), 422, 'Este producto no se ofrece en esa sede: asígnalo a la sede desde Editar producto antes de registrar existencias.');

        if (! $model->tracks_inventory) {
            abort(422, 'Este producto no tiene control de inventario: actívalo antes de registrar existencias.');
        }

        return [$model, $locationId];
    }
}
