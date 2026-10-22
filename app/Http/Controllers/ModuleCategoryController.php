<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveModuleCategoryRequest;
use App\Models\ModuleCategory;
use App\Services\ModuleCatalog;
use Illuminate\Http\RedirectResponse;

/** Categories of the module catalog. They only organize modules (no code is needed for a new one) and are never deleted. */
class ModuleCategoryController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function store(SaveModuleCategoryRequest $request): RedirectResponse
    {
        $this->catalog->createCategory($request->validated());

        return $this->back('Categoría creada correctamente');
    }

    public function update(SaveModuleCategoryRequest $request, ModuleCategory $category): RedirectResponse
    {
        $this->catalog->updateCategory($category, $request->validated());

        return $this->back('Categoría actualizada correctamente');
    }

    public function activate(ModuleCategory $category): RedirectResponse
    {
        $this->catalog->setCategoryActive($category, true);

        return $this->back('Categoría activada correctamente');
    }

    public function deactivate(ModuleCategory $category): RedirectResponse
    {
        $this->catalog->setCategoryActive($category, false);

        return $this->back('Categoría desactivada correctamente');
    }

    public function publish(ModuleCategory $category): RedirectResponse
    {
        $this->catalog->setCategoryPublic($category, true);

        return $this->back('Solución visible en la oferta pública');
    }

    public function unpublish(ModuleCategory $category): RedirectResponse
    {
        $this->catalog->setCategoryPublic($category, false);

        return $this->back('Solución oculta de la oferta pública');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->back(fallback: route('plans.index', ['tab' => 'modulos']))->with('success', $message);
    }
}
