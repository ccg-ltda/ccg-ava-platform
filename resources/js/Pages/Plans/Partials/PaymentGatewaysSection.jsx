import Alert from '@/Components/Alert';
import Checkbox from '@/Components/Checkbox';
import SectionCard from './SectionCard';

/**
 * The payment gateways the plan includes. Closed by default: a plan that never chose any includes none (unlike channels). A Workspace can only collect with a gateway its plan includes
 * (or that an administrator granted it) AND whose connection is complete, active and validated; the server checks it on every operation. A gateway left out keeps the connection and the
 * history of the Workspace and works again when a plan includes it.
 */
export default function PaymentGatewaysSection({ form, commercial, ready = true }) {
    const selected = form.data.payment_gateways ?? [];
    const toggle = (key) => form.setData('payment_gateways', selected.includes(key) ? selected.filter((item) => item !== key) : [...selected, key]);

    return (
        <SectionCard id="plan-card-payment-gateways" title="Pasarelas de pago" description="Los proveedores con los que el Workspace suscriptor podrá cobrar sus pedidos. Cada Workspace conecta sus propias credenciales; aquí solo se decide si puede hacerlo.">
            {!ready && <Alert tone="warning">Las pasarelas todavía no se pueden guardar: falta aplicar la migración pendiente en esta base de datos.</Alert>}
            {form.errors.payment_gateways && <Alert tone="danger">{form.errors.payment_gateways}</Alert>}
            <ul className={`grid gap-3 sm:grid-cols-2 lg:grid-cols-3 ${ready ? '' : 'pointer-events-none opacity-60'}`} aria-label="Pasarelas del plan">
                {commercial.paymentGateways.map((gateway) => (
                    <li key={gateway.key}>
                        <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-line bg-card px-4 py-3 text-sm text-ink hover:border-primary/50">
                            <Checkbox checked={selected.includes(gateway.key)} onChange={() => toggle(gateway.key)} />
                            <span className="font-semibold">{gateway.label}</span>
                        </label>
                    </li>
                ))}
            </ul>
            <p className="text-xs text-ink-muted">{selected.length === 0 ? 'Ninguna pasarela: el Workspace no podrá cobrar con tarjeta.' : `${selected.length} de ${commercial.paymentGateways.length} pasarelas incluidas.`}</p>
        </SectionCard>
    );
}
