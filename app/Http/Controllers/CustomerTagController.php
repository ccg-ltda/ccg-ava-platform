<?php

namespace App\Http\Controllers;

use App\Audit\AuditLogger;
use App\Customers\CustomerTagCatalog;
use App\Models\CustomerTagDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Administration of the customer tags of the ACTIVE Workspace (tab «Etiquetas» of Clientes). Reading is `view-customers`; creating needs `create-customers` or
 * `edit-customers`; renaming, (de)activating and deleting need `edit-customers`. A tag that customers have is never deleted (it is deactivated: it is not offered
 * again and the customers keep it); an unused one can be deleted. Everything starts from the Workspace of the session, so a tag of another Workspace is a 404.
 */
class CustomerTagController extends Controller
{
    public function __construct(private readonly CustomerTagCatalog $tags, private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:'.CustomerTagCatalog::MAX_LENGTH]], ['name.required' => 'Escribe el nombre de la etiqueta.'], ['name' => 'el nombre de la etiqueta']);
        $workspace = $request->attributes->get('workspace');

        [$tag, $created] = $this->tags->ensure($workspace, $data['name']);

        if (! $created) {
            // A duplicate is a mistake to correct, not a success: the name is already taken (in any capitalization, spacing or Unicode form).
            return back()->withErrors(['name' => "Ya existe la etiqueta «{$tag->name}»."]);
        }

        $this->audit->record('created', 'customer_tag', $tag->id, $tag->name, [AuditLogger::change('Nombre', null, $tag->name)], $workspace);

        return back()->with('success', 'Etiqueta creada correctamente');
    }

    public function update(Request $request, int $tag): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $this->find($request, $tag);
        $data = $request->validate(['name' => ['required', 'string', 'max:'.CustomerTagCatalog::MAX_LENGTH]], ['name.required' => 'Escribe el nombre de la etiqueta.'], ['name' => 'el nombre de la etiqueta']);
        $before = $model->name;

        $this->tags->rename($model, $data['name']);

        if ($before !== $model->name) {
            $this->audit->record('updated', 'customer_tag', $model->id, $model->name, [AuditLogger::change('Nombre', $before, $model->name)], $request->attributes->get('workspace'));
        }

        return back()->with('success', 'Etiqueta actualizada correctamente');
    }

    public function activate(Request $request, int $tag): RedirectResponse
    {
        return $this->toggle($request, $tag, true);
    }

    public function deactivate(Request $request, int $tag): RedirectResponse
    {
        return $this->toggle($request, $tag, false);
    }

    public function destroy(Request $request, int $tag): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $this->find($request, $tag);
        $name = $model->name;

        $this->tags->delete($model);
        $this->audit->record('deleted', 'customer_tag', $tag, $name, [AuditLogger::change('Nombre', $name, null)], $request->attributes->get('workspace'));

        return back()->with('success', 'Etiqueta eliminada correctamente');
    }

    private function toggle(Request $request, int $tag, bool $active): RedirectResponse
    {
        if ($refused = $this->refuseWithoutSchema()) {
            return $refused;
        }

        $model = $this->find($request, $tag);

        if ($model->is_active !== $active) {
            $model->update(['is_active' => $active]);
            $this->audit->record('updated', 'customer_tag', $model->id, $model->name, [AuditLogger::change('Activa', $active ? 'No' : 'Sí', $active ? 'Sí' : 'No')], $request->attributes->get('workspace'));
        }

        return back()->with('success', $active ? 'Etiqueta activada correctamente' : 'Etiqueta desactivada correctamente')
            ->with('warning', $active ? null : 'Ya no se ofrece a clientes nuevos. Los clientes que la tienen la conservan.');
    }

    private function find(Request $request, int $tag): CustomerTagDefinition
    {
        return CustomerTagDefinition::where('workspace_id', $request->attributes->get('workspace')->id)->findOrFail($tag);
    }

    private function refuseWithoutSchema(): ?RedirectResponse
    {
        return $this->tags->ready() ? null : back()->with('error', 'Falta aplicar la última migración de la base de datos: un administrador debe ejecutar «php artisan migrate».');
    }
}
