import { Head, useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { formatMoney } from '@/lib/format';

const NOTICE = {
    paid: { tone: 'success', text: 'Este pedido ya está pagado. Gracias.' },
    pending: { tone: 'info', text: 'Hay un pago en curso para este pedido. Puedes intentarlo de nuevo si no lo completaste.' },
    verification: { tone: 'warning', text: 'Estamos verificando tu pago con el proveedor. No pagues otra vez: te lo confirmaremos en unos minutos.' },
    refund_pending: { tone: 'info', text: 'El reembolso de este pedido está en curso.' },
    refunded: { tone: 'info', text: 'El pago de este pedido fue reembolsado.' },
};

/** The page a customer opens from the payment link: the amount and the methods come from the server; the browser only chooses a method. */
export default function Show({ business, payment, payable, gateways, attemptKey, startUrl, errors }) {
    const form = useForm({ gateway: '', attempt: attemptKey });
    const notice = NOTICE[payment.state];
    const amount = formatMoney(payment.amountMinor / 100, payment.currency);

    const pay = (key) => {
        form.transform((data) => ({ ...data, gateway: key })).post(startUrl);
    };

    return (
        <GuestLayout title={`Pagar ${payment.description}`}>
            <Head title="Pagar pedido" />
            <p className="text-sm text-ink-muted">{business}</p>
            <p className="mt-3 text-3xl font-extrabold text-ink">{amount}</p>
            <p className="text-xs text-ink-muted">{payment.currency}</p>

            {errors?.payment && <Alert tone="danger" className="mt-4">{errors.payment}</Alert>}
            {notice && <Alert tone={notice.tone} className="mt-4">{notice.text}</Alert>}
            {!payable && <Alert tone="danger" className="mt-4">Este pedido ya no se puede pagar.</Alert>}

            {payable && gateways.length > 0 && (
                <div className="mt-6 space-y-3">
                    <p className="text-sm font-semibold text-ink">Elige cómo pagar</p>
                    {gateways.map((gateway) => (
                        <PrimaryButton key={gateway.key} type="button" onClick={() => pay(gateway.key)} disabled={form.processing} className="min-h-11 w-full justify-center">
                            Pagar con {gateway.label}
                        </PrimaryButton>
                    ))}
                    <p className="text-xs text-ink-muted">Pagarás en el sitio seguro del proveedor. Ava no ve ni guarda los datos de tu tarjeta.</p>
                </div>
            )}

            {payable && gateways.length === 0 && !notice && <Alert tone="warning" className="mt-4">Este comercio no tiene métodos de pago disponibles ahora. Contáctalo para pagar.</Alert>}
        </GuestLayout>
    );
}
