<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReminderRuleRequest;
use App\Models\ReminderRule;
use App\Reminders\ReminderRulePresenter;
use App\Reminders\ReminderRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Automatic reminder rules («30 days after a customer had service X») of the ACTIVE Workspace, taken from the request context, never from the client. Needs the Recordatorios module AND
 * Agenda (the services done are appointments); each operation has its own permission and every query starts from the Workspace, so a rule of another one is a 404. The rules
 * are listed on the Recordatorios page; this controller only creates, edits, switches them and shows their history.
 */
class ReminderRuleController extends Controller
{
    public function __construct(private readonly ReminderRuleService $rules, private readonly ReminderRulePresenter $presenter) {}

    public function store(SaveReminderRuleRequest $request): RedirectResponse
    {
        $this->rules->create($request->attributes->get('workspace'), $request->validated(), $request->user());

        return back()->with('success', 'Regla creada. Desde ahora programa un recordatorio por cada servicio realizado.');
    }

    public function update(SaveReminderRuleRequest $request, int $rule): RedirectResponse
    {
        $this->rules->update($this->find($request, $rule), $request->validated());

        return back()->with('success', 'Regla actualizada.');
    }

    public function activate(Request $request, int $rule): RedirectResponse
    {
        $this->rules->setActive($this->find($request, $rule), true);

        return back()->with('success', 'Regla activada. Cuenta los servicios que se realicen desde ahora.');
    }

    public function deactivate(Request $request, int $rule): RedirectResponse
    {
        $this->rules->setActive($this->find($request, $rule), false);

        return back()->with('success', 'Regla desactivada. Los recordatorios que programó y no salieron se cancelaron.');
    }

    /** What the rule decided for each appointment (read only, fixed reasons). */
    public function events(Request $request, int $rule): JsonResponse
    {
        return response()->json($this->presenter->events($this->find($request, $rule), $request));
    }

    private function find(Request $request, int $id): ReminderRule
    {
        return $request->attributes->get('workspace')->reminderRules()->with('workspace')->findOrFail($id);
    }
}
