<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWorkspaceGatewayRequest;
use App\Models\Workspace;
use App\Payments\Gateways\GatewayOverrides;
use Illuminate\Http\RedirectResponse;

/**
 * The administrative exception of ONE client Workspace over its plan for a payment gateway. Only the platform administration reaches it (`workspace.platform:assign-plans`,
 * the same permission that assigns plans and module exceptions); a Workspace can never enable a gateway for itself. The exception only makes the gateway PERMITTED: the
 * connection must still be complete, active and validated (GatewayAvailability). Every change is audited with its reason, author and moment.
 */
class WorkspaceGatewayController extends Controller
{
    public function __construct(private readonly GatewayOverrides $overrides) {}

    public function update(SaveWorkspaceGatewayRequest $request, Workspace $workspace): RedirectResponse
    {
        abort_if($workspace->isAdministrative(), 404);

        $data = $request->validated();

        if ($data['mode'] === 'clear') {
            $this->overrides->clear($workspace, $data['gateway']);
        } else {
            $this->overrides->set($workspace, $data['gateway'], $data['mode'], $data['reason'], $request->user());
        }

        return back()->with('success', 'Pasarelas de pago del Workspace actualizadas correctamente');
    }
}
