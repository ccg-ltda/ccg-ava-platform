<?php

namespace App\Payments\Gateways;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentGatewayOverride;
use Illuminate\Validation\ValidationException;

/**
 * The administrative grant/revoke of a gateway for ONE Workspace over its plan. Only platform administration calls this (the route that exposes it sits behind
 * `workspace.platform`); a Workspace can never enable a gateway for itself. A grant makes the gateway PERMITTED, nothing more: the connection still has to be complete,
 * active and validated (GatewayAvailability). Every change is audited with its reason.
 */
class GatewayOverrides
{
    private const MODES = ['grant' => 'Concedida', 'revoke' => 'Revocada'];

    public function __construct(private readonly PaymentGatewayManager $gateways, private readonly AuditLogger $audit, private readonly GatewayAvailability $availability) {}

    /**
     * What the administration sees of one Workspace: for every gateway, what its plan says, the exception (who, when, why) and the EFFECTIVE state with the reason. The
     * effective state is the same computation the customer's page and the checkout use.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Workspace $workspace): array
    {
        $overrides = WorkspacePaymentGatewayOverride::with('author:id,name')->where('workspace_id', $workspace->id)->get()->keyBy('gateway');

        return array_map(function (string $key) use ($workspace, $overrides) {
            $override = $overrides->get($key);
            $state = $this->availability->describe($workspace, $key);

            return [
                'key' => $key, 'label' => $state['label'], 'inPlan' => $this->availability->planAllows($workspace, $key),
                'override' => $override ? ['mode' => $override->mode, 'reason' => $override->reason, 'by' => $override->author?->name, 'at' => $override->updated_at->toIso8601String()] : null,
                'state' => $state['state'], 'available' => $state['available'], 'reasons' => $state['reasons'], 'environment' => $state['environment'],
            ];
        }, $this->gateways->keys());
    }

    /** @throws ValidationException */
    public function set(Workspace $workspace, string $gateway, string $mode, ?string $reason, ?User $by): WorkspacePaymentGatewayOverride
    {
        if (! $this->gateways->has($gateway) || ! in_array($mode, [WorkspacePaymentGatewayOverride::GRANT, WorkspacePaymentGatewayOverride::REVOKE], true)) {
            throw ValidationException::withMessages(['gateway' => 'La pasarela o el modo no son válidos.']);
        }

        if ($workspace->isAdministrative()) {
            throw ValidationException::withMessages(['gateway' => 'El Workspace administrativo ya dispone de todas las pasarelas.']);
        }

        $reason = $reason === null ? null : mb_substr(trim($reason), 0, 255);

        if ($reason === null || mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo de la excepción (mínimo 5 caracteres).']);
        }

        $before = WorkspacePaymentGatewayOverride::where('workspace_id', $workspace->id)->where('gateway', $gateway)->first();
        $override = WorkspacePaymentGatewayOverride::updateOrCreate(['workspace_id' => $workspace->id, 'gateway' => $gateway], ['mode' => $mode, 'reason' => $reason ?: null, 'created_by' => $by?->id]);
        $label = $this->gateways->adapter($gateway)->label();

        $this->audit->record('updated', 'payment_gateway', $workspace->id, $workspace->name, [
            AuditLogger::change($label, $before ? self::MODES[$before->mode] : 'Según el plan', self::MODES[$mode]),
            AuditLogger::change('Motivo de '.$label, $before?->reason, $reason),
        ], $workspace);

        return $override;
    }

    /** Back to what the plan says. */
    public function clear(Workspace $workspace, string $gateway): void
    {
        $removed = WorkspacePaymentGatewayOverride::where('workspace_id', $workspace->id)->where('gateway', $gateway)->first();

        if ($removed) {
            $removed->delete();
            $this->audit->record('updated', 'payment_gateway', $workspace->id, $workspace->name, [AuditLogger::change($this->gateways->adapter($gateway)->label(), self::MODES[$removed->mode], 'Según el plan')], $workspace);
        }
    }
}
