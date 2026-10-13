<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveModuleRequest;
use App\Models\Module;
use App\Services\ModuleCatalog;
use Illuminate\Http\RedirectResponse;

/**
 * Catalog of modules. Registering a module reserves a place for a future feature: it does not build it, and it stays
 * "pending implementation" until its key is declared in config/modules.php. Modules are never deleted.
 */
class ModuleController extends Controller
{
    public function __construct(private readonly ModuleCatalog $catalog) {}

    public function store(SaveModuleRequest $request): RedirectResponse
    {
        $this->catalog->createModule($request->validated());

        return $this->back('Módulo registrado correctamente');
    }

    public function update(SaveModuleRequest $request, Module $module): RedirectResponse
    {
        $this->catalog->updateModule($module, $request->validated());

        return $this->back('Módulo actualizado correctamente');
    }

    public function activate(Module $module): RedirectResponse
    {
        $this->catalog->setModuleActive($module, true);

        return $this->back('Módulo activado correctamente');
    }

    public function deactivate(Module $module): RedirectResponse
    {
        $this->catalog->setModuleActive($module, false);

        return $this->back('Módulo desactivado correctamente');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->back(fallback: route('plans.index', ['tab' => 'modulos']))->with('success', $message);
    }
}
