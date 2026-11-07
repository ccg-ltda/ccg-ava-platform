import { router } from '@inertiajs/react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { day, money } from '@/config/orders';

const TONES = { paid: 'green', duplicate: 'red', pending: 'amber', verification: 'amber', refund_pending: 'violet', refunded: 'neutral', unpaid: 'neutral' };
const TRANSACTION_TONES = { approved: 'green', declined: 'red', cancelled: 'neutral', expired: 'neutral', refunded: 'neutral', refund_pending: 'violet' };

/** The payment of an order, DERIVED by the server from its transactions: the screen never decides that something is paid. */
export default function PaymentCard({ order, payment }) {
    const [revoking, setRevoking] = useState(false);

    return (
        <Card className="space-y-4 p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <h2 className="text-sm font-semibold text-ink">Pago</h2>
                    <Badge tone={TONES[payment.state] ?? 'neutral'}>{payment.stateLabel}</Badge>
                </div>
                {payment.canCollect && (
                    <div className="flex flex-wrap gap-2">
                        <PrimaryButton type="button" disabled={payment.methods.length === 0} onClick={() => router.post(route('orders.payment-link.store', order.id), {}, { preserveScroll: true })}>
                            Generar enlace de pago
                        </PrimaryButton>
                        <SecondaryButton type="button" onClick={() => setRevoking(true)}>
                            Revocar enlaces
                        </SecondaryButton>
                    </div>
                )}
            </div>

            {payment.canCollect && payment.methods.length === 0 && <p className="text-sm text-ink-muted">Este Workspace no tiene ninguna pasarela disponible. Configúrala en Integraciones.</p>}
            {payment.canCollect && payment.methods.length > 0 && <p className="text-sm text-ink-muted">El cliente podrá pagar con: {payment.methods.join(', ')}.</p>}

            {payment.state === 'duplicate' && (
                <Alert tone="danger">
                    Este pedido tiene más de un pago aprobado. Nada se perdió: ambos están en la lista. Devuelve el cobro sobrante desde el portal del proveedor; cuando el proveedor lo informe, Ava lo marcará como reembolsado.
                </Alert>
            )}
            {payment.state === 'verification' && <Alert tone="warning">Hay un pago que Ava no pudo confirmar todavía. Usa «Verificar» para preguntar al proveedor de nuevo; no generes otro cobro.</Alert>}

            {payment.link && (
                <div className="space-y-2">
                    <p className="text-sm font-medium text-ink">Enlace para el cliente</p>
                    <CopyBlock value={payment.link} label="Enlace de pago" message="Enlace copiado" />
                    <p className="text-xs text-ink-muted">Se muestra una sola vez. Caduca en 72 horas o cuando lo revoques.</p>
                </div>
            )}

            {payment.transactions.length > 0 && (
                <ul className="divide-y divide-line rounded-lg border border-line">
                    {payment.transactions.map((transaction) => (
                        <li key={transaction.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                            <span className="font-mono text-xs text-ink-muted">{transaction.reference}</span>
                            <span className="text-ink">{money(transaction.amountMinor / 100, transaction.currency)}</span>
                            <Badge tone={TRANSACTION_TONES[transaction.status] ?? 'amber'}>{transaction.statusLabel}</Badge>
                            {transaction.duplicate && <Badge tone="red">Duplicado</Badge>}
                            {transaction.review && <Badge tone="amber">Revisión manual</Badge>}
                            <span className="text-xs text-ink-muted">{day(transaction.at)}</span>
                            {transaction.canVerify && (
                                <SecondaryButton type="button" onClick={() => router.post(route('orders.payments.verify', [order.id, transaction.id]), {}, { preserveScroll: true })}>
                                    Verificar
                                </SecondaryButton>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                show={revoking}
                title="Revocar enlaces de pago"
                description="Los enlaces que ya entregaste dejarán de funcionar. Un pago que el cliente ya inició se sigue verificando."
                confirmLabel="Revocar"
                onCancel={() => setRevoking(false)}
                onConfirm={() => router.delete(route('orders.payment-link.destroy', order.id), { preserveScroll: true, onFinish: () => setRevoking(false) })}
            />
        </Card>
    );
}
