<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Booking\ServiceCategories;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Administration of the categories of the services of the ACTIVE Workspace (modal «Categorías» of Servicios). Creating needs `create-services` or `edit-services`;
 * renaming and (de)activating need `edit-services`. A category is never deleted and its services are never moved: it can be renamed (the services follow, they are
 * linked by id) or deactivated (it stops being offered to new assignments; the services that have it keep it). A category of another Workspace is a 404.
 */
class ServiceCategoryController extends Controller
{
    public function __construct(private readonly ServiceCategories $categories, private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:80']], ['name.required' => 'Escribe el nombre de la categoría.'], ['name' => 'el nombre de la categoría']);
        $workspace = $request->attributes->get('workspace');

        if (ServiceCategories::normalize($data['name']) === '') {
            return back()->withErrors(['name' => 'Escribe el nombre de la categoría.']);
        }

        [$category, $created] = $this->categories->ensure($workspace, $data['name']);

        if (! $created) {
            return back()->withErrors(['name' => "Ya existe la categoría «{$category->name}»."]);
        }

        $this->audit->record('created', 'service_category', $category->id, $category->name, [AuditLogger::change('Nombre', null, $category->name)], $workspace);

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
            $this->audit->record('updated', 'service_category', $model->id, $model->name, [AuditLogger::change('Nombre', $before, $model->name)], $request->attributes->get('workspace'));
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
            $this->audit->record('updated', 'service_category', $model->id, $model->name, [AuditLogger::change('Activa', $active ? 'No' : 'Sí', $active ? 'Sí' : 'No')], $request->attributes->get('workspace'));
        }

        return back()->with('success', $active ? 'Categoría activada correctamente' : 'Categoría desactivada correctamente')
            ->with('warning', $active ? null : 'Ya no se ofrece a servicios nuevos. Los servicios que la tienen la conservan.');
    }

    private function find(Request $request, int $category): ServiceCategory
    {
        return ServiceCategory::where('workspace_id', $request->attributes->get('workspace')->id)->findOrFail($category);
    }

    private function refuseWithoutSchema(): ?RedirectResponse
    {
        return $this->categories->ready() ? null : back()->with('error', 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
    }
}
