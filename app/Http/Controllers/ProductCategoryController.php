<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Models\ProductCategory;
use App\Orders\CommerceSchema;
use App\Orders\ProductCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Administration of the categories of the products of the ACTIVE Workspace (tab «Categorías» of the Catálogo). Reading is `view-catalog`; creating needs
 * `create-products` or `edit-products`; renaming and (de)activating need `edit-products`. A category is never deleted and its products are never moved: it can be
 * renamed (the products follow, they are linked by id) or deactivated (it stops being offered to new products; the products that have it keep it).
 * Everything starts from the Workspace of the session, so a category of another Workspace is a 404.
 */
class ProductCategoryController extends Controller
{
    public function __construct(private readonly ProductCategories $categories, private readonly CommerceSchema $schema, private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:80']], ['name.required' => 'Escribe el nombre de la categoría.'], ['name' => 'el nombre de la categoría']);
        $workspace = $request->attributes->get('workspace');

        if (ProductCategories::normalize($data['name']) === '') {
            return back()->withErrors(['name' => 'Escribe el nombre de la categoría.']);
        }

        [$category, $created] = $this->categories->ensure($workspace, $data['name']);

        if (! $created) {
            // A duplicate is a mistake to correct, not a success: the name is already taken (in any capitalization or spacing).
            return back()->withErrors(['name' => "Ya existe la categoría «{$category->name}»."]);
        }

        $this->audit->record('created', 'product_category', $category->id, $category->name, [AuditLogger::change('Nombre', null, $category->name)], $workspace);

        return back()->with('success', 'Categoría creada correctamente');
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $this->find($request, $category);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']], ['name.required' => 'Escribe el nombre de la categoría.'], ['name' => 'el nombre de la categoría']);
        $before = $model->name;

        $this->categories->rename($model, $data['name']);

        if ($before !== $model->name) {
            $this->audit->record('updated', 'product_category', $model->id, $model->name, [AuditLogger::change('Nombre', $before, $model->name)], $request->attributes->get('workspace'));
        }

        return back()->with('success', 'Categoría actualizada correctamente');
    }

    public function activate(Request $request, int $category): RedirectResponse
    {
        return $this->toggle($request, $category, true);
    }

    public function deactivate(Request $request, int $category): RedirectResponse
    {
        return $this->toggle($request, $category, false);
    }

    private function toggle(Request $request, int $category, bool $active): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $this->find($request, $category);

        if ($model->is_active !== $active) {
            $model->update(['is_active' => $active]);
            $this->audit->record('updated', 'product_category', $model->id, $model->name, [AuditLogger::change('Activa', $active ? 'No' : 'Sí', $active ? 'Sí' : 'No')], $request->attributes->get('workspace'));
        }

        return back()->with('success', $active ? 'Categoría activada correctamente' : 'Categoría desactivada correctamente')
            ->with('warning', $active ? null : 'Ya no se ofrece a productos nuevos. Los productos que la tienen la conservan.');
    }

    private function find(Request $request, int $category): ProductCategory
    {
        return ProductCategory::where('workspace_id', $request->attributes->get('workspace')->id)->findOrFail($category);
    }

    private function refuseWithoutSchema(): ?RedirectResponse
    {
        return $this->schema->inventoryReady() ? null : back()->with('error', 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
    }
}
