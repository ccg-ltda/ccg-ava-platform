<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\WorkspaceLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The particular limits of ONE client Workspace over its plan's standard. Only the platform administration reaches it (`workspace.platform:assign-plans`):
 * a client never changes its own limits. The Workspace comes from the route and is re-checked by the service; the plan and every other Workspace are untouched.
 */
class WorkspaceLimitController extends Controller
{
    public function __construct(private readonly WorkspaceLimits $limits) {}

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'metric' => ['required', 'string', Rule::in(array_keys(WorkspaceLimits::METRICS))],
            // set = a number for this Workspace; unlimited = no limit for it; reset = back to the plan's standard.
            'mode' => ['required', 'string', Rule::in(['set', 'unlimited', 'reset'])],
            'value' => ['required_if:mode,set', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['required_unless:mode,reset', 'nullable', 'string', 'min:5', 'max:500'],
        ], [
            'value.required_if' => 'Indica el máximo que tendrá este Workspace.',
            'reason.required_unless' => 'Escribe el motivo de la excepción (al menos 5 caracteres).',
        ]);

        match ($data['mode']) {
            'set' => $this->limits->setOverride($workspace, $data['metric'], (int) $data['value'], (string) $data['reason'], $request->user()->id),
            'unlimited' => $this->limits->setOverride($workspace, $data['metric'], null, (string) $data['reason'], $request->user()->id),
            'reset' => $this->limits->clearOverride($workspace, $data['metric']),
        };

        return back()->with('success', 'Límite del Workspace actualizado correctamente');
    }
}
