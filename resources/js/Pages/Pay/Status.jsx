import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import Alert from '@/Components/Alert';
import GuestLayout from '@/Layouts/GuestLayout';
import { formatMoney } from '@/lib/format';

const COPY = {
    approved: { tone: 'success', text: 'Pago aprobado. Gracias por tu compra.' },
    declined: { tone: 'danger', text: 'El pago fue rechazado. Puedes intentarlo de nuevo con el enlace que recibiste.' },
    cancelled: { tone: 'info', text: 'El pago fue cancelado. No se te cobró.' },
    expired: { tone: 'info', text: 'El tiempo para pagar terminó. Puedes intentarlo de nuevo con el enlace que recibiste.' },
    refunded: { tone: 'info', text: 'Este pago fue reembolsado.' },
    refund_pending: { tone: 'info', text: 'El reembolso de este pago está en curso.' },
    pending: { tone: 'info', text: 'La transacción está pendiente de confirmación.' },
    processing: { tone: 'info', text: 'La transacción está pendiente de confirmación.' },
    verification_required: { tone: 'warning', text: 'Estamos verificando tu pago con el proveedor. No pagues otra vez: se confirmará en unos minutos.' },
};

/** What the SERVER knows about the payment. The browser coming back proves nothing: while the answer is open, the page asks again by itself. */
export default function Status({ business, payment }) {
    useEffect(() => {
        if (!payment.open) return undefined;
        const timer = setInterval(() => router.reload({ only: ['payment'] }), 5000);

        return () => clearInterval(timer);
    }, [payment.open, payment.state]);

    const copy = COPY[payment.state] ?? COPY.pending;

    return (
        <GuestLayout title="Estado de tu pago">
            <Head title="Estado del pago" />
            <p className="text-sm text-ink-muted">{business}</p>
            <p className="mt-3 text-2xl font-extrabold text-ink">{formatMoney(payment.amountMinor / 100, payment.currency)}</p>
            <p className="mt-1 text-xs text-ink-muted">Referencia {payment.reference}</p>
            <Alert tone={copy.tone} className="mt-4">{copy.text}</Alert>
        </GuestLayout>
    );
}
