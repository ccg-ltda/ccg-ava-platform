import { useForm } from '@inertiajs/react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';

const STATES = {
    validated: ['green', 'Lista para cobrar'],
    plan_disabled: ['neutral', 'No incluida en el plan'],
    admin_disabled: ['red', 'Revocada por administración'],
    not_configured: ['amber', 'Sin configurar'],
    incomplete: ['amber', 'Configuración incompleta'],
    saved: ['amber', 'Guardada, sin validar'],
    error: ['red', 'Error de conexión'],
    inactive: ['neutral', 'Desactivada'],
    blocked_environment: ['amber', 'Producción no autorizada'],
    incompatible: ['amber', 'Moneda o país no compatibles'],
};

const when = (iso) => new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });

function GatewayRow({ workspace, gateway, canEdit }) {
    const form = useForm({ gateway: gateway.key, mode: gateway.override?.mode ?? 'grant', reason: '' });
    const [tone, label] = STATES[gateway.state] ?? ['neutral', gateway.state];
    const submit = (event) => {
        event.preventDefault();
        form.put(route('workspaces.payment-gateways.update', workspace.id), { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
    };

    return (
        <li className="space-y-3 rounded-xl border border-line p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-ink">{gateway.label}</p>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={gateway.inPlan ? 'blue' : 'neutral'}>{gateway.inPlan ? 'En el plan' : 'No está en el plan'}</Badge>
                    {gateway.override && <Badge tone={gateway.override.mode === 'grant' ? 'green' : 'red'}>{gateway.override.mode === 'grant' ? 'Concedida' : 'Revocada'}</Badge>}
                    <Badge tone={tone}>{label}</Badge>
                </div>
            </div>
            {gateway.reasons.length > 0 && <p className="text-sm text-ink-muted">{gateway.reasons.join(' ')}</p>}
            {gateway.override && (
                <p className="text-xs text-ink-muted">
                    Excepción por «{gateway.override.reason}» · {gateway.override.by ?? 'usuario retirado'} · {when(gateway.override.at)}
                </p>
            )}
            {canEdit && (
                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[12rem_1fr_auto] sm:items-end" noValidate>
                    <div>
                        <InputLabel htmlFor={`gw_mode_${gateway.key}`} value="Acción" />
                        <Select id={`gw_mode_${gateway.key}`} className="mt-1" value={form.data.mode} onChange={(event) => form.setData('mode', event.target.value)}>
                            <option value="grant">Conceder</option>
                            <option value="revoke">Revocar</option>
                            {gateway.override && <option value="clear">Quitar la excepción</option>}
                        </Select>
                    </div>
                    <div>
                        <InputLabel htmlFor={`gw_reason_${gateway.key}`} value="Motivo (obligatorio)" />
                        <TextInput id={`gw_reason_${gateway.key}`} className="mt-1" maxLength={255} value={form.data.reason} disabled={form.data.mode === 'clear'} onChange={(event) => form.setData('reason', event.target.value)} invalid={Boolean(form.errors.reason)} />
                        <InputError message={form.errors.reason ?? form.errors.mode} className="mt-1" />
                    </div>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Guardar
                    </PrimaryButton>
                </form>
            )}
        </li>
    );
}

/**
 * The payment gateways of one Workspace for the platform administration: what its plan says, the exception (who, when, why) and what is effectively available. An exception only
 * PERMITS a gateway; the connection still has to be complete, active and validated, so granting never bypasses a safety check.
 */
export default function WorkspaceGatewaysCard({ workspace, gateways, canEdit }) {
    return (
        <Card className="space-y-4 p-4 sm:p-6">
            <div>
                <h2 className="text-base font-bold text-ink">Pasarelas de pago</h2>
                <p className="text-sm text-ink-muted">El plan decide qué pasarelas puede usar el Workspace; aquí la administración puede conceder o revocar una en particular. Nunca activa una conexión incompleta ni sin validar.</p>
            </div>
            <ul className="space-y-3" aria-label="Pasarelas de pago del Workspace">
                {gateways.map((gateway) => (
                    <GatewayRow key={gateway.key} workspace={workspace} gateway={gateway} canEdit={canEdit} />
                ))}
            </ul>
        </Card>
    );
}
