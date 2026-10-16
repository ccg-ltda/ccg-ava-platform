import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, MapPin } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { day, money } from '@/config/orders';
import AppLayout from '@/Layouts/AppLayout';
import OrderStatusBadge from './Partials/OrderStatusBadge';

function CancelModal({ order, onClose }) {
    const form = useForm({ status: 'cancelled', reason: '' });

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('orders.transition', order.id), { preserveScroll: true, onSuccess: onClose });
                }}
                className="space-y-4 p-5 sm:p-6"
            >
                <h2 className="text-lg font-bold text-ink">Cancelar el pedido #{order.number}</h2>
                <p className="text-sm text-ink-muted">Un pedido cancelado no se puede reabrir. Queda registrado con su motivo.</p>
                <div>
                    <InputLabel htmlFor="cancel_reason" value="Motivo" />
                    <Textarea id="cancel_reason" rows={3} className="mt-1" value={form.data.reason} maxLength={255} onChange={(e) => form.setData('reason', e.target.value)} />
                    <InputError message={form.errors.reason} className="mt-1" />
                </div>
                <div className="flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Volver
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Cancelar pedido
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function DetailsForm({ order }) {
    const form = useForm({ notes: order.notes ?? '', delivery_address: order.address ?? '', delivery_city: order.city ?? '', delivery_reference: order.reference ?? '', recipient_name: order.recipientName ?? '', recipient_phone: order.recipientPhone ?? '' });
    const { data, setData, errors } = form;
    const delivery = order.method === 'delivery';

    return (
        <form
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                form.put(route('orders.update', order.id), { preserveScroll: true });
            }}
            className="space-y-3"
        >
            {delivery && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="det_address" value="Dirección de entrega" />
                        <TextInput id="det_address" className="mt-1" value={data.delivery_address} maxLength={255} onChange={(e) => setData('delivery_address', e.target.value)} invalid={Boolean(errors.delivery_address)} />
                        <InputError message={errors.delivery_address} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="det_city" value="Ciudad" />
                        <TextInput id="det_city" className="mt-1" value={data.delivery_city} maxLength={120} onChange={(e) => setData('delivery_city', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel htmlFor="det_reference" value="Referencias" />
                        <TextInput id="det_reference" className="mt-1" value={data.delivery_reference} maxLength={255} onChange={(e) => setData('delivery_reference', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel htmlFor="det_recipient" value="Quién recibe" />
                        <TextInput id="det_recipient" className="mt-1" value={data.recipient_name} maxLength={120} onChange={(e) => setData('recipient_name', e.target.value)} />
                    </div>
                    <div>
                        <InputLabel htmlFor="det_recipient_phone" value="Teléfono de quien recibe" />
                        <TextInput id="det_recipient_phone" type="tel" placeholder="+573001112233" className="mt-1" value={data.recipient_phone} maxLength={32} onChange={(e) => setData('recipient_phone', e.target.value)} invalid={Boolean(errors.recipient_phone)} />
                        <InputError message={errors.recipient_phone} className="mt-1" />
                    </div>
                </div>
            )}
            <div>
                <InputLabel htmlFor="det_notes" value="Notas operativas" />
                <Textarea id="det_notes" rows={2} className="mt-1" value={data.notes} maxLength={500} onChange={(e) => setData('notes', e.target.value)} />
                <InputError message={errors.notes} className="mt-1" />
            </div>
            <PrimaryButton type="submit" disabled={form.processing}>
                Guardar cambios
            </PrimaryButton>
        </form>
    );
}

/** One order: what was bought as it was bought, its delivery data, the moves available to this person and its history. */
export default function Show({ order, moves, canEdit, statuses, notifier }) {
    const [cancelling, setCancelling] = useState(false);
    const move = (status) => router.post(route('orders.transition', order.id), { status }, { preserveScroll: true });
    const forward = moves.filter((item) => item.status !== 'cancelled');
    const canCancel = moves.some((item) => item.status === 'cancelled');

    return (
        <>
            <Head title={`Pedido #${order.number}`} />

            <Link href={route('orders.index')} className="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Pedidos
            </Link>
            <PageHeader title={`Pedido #${order.number}`} description={`${order.customerName} · ${order.methodLabel}${order.location ? ` · ${order.location}` : ''}`}>
                <OrderStatusBadge status={order.status} label={order.statusLabel} />
            </PageHeader>

            <Alert tone="info">Este pedido está registrado, no necesariamente pagado: Ava no gestiona pagos.{!notifier && ' Tampoco avisa todavía al cliente de los cambios de estado.'}</Alert>

            {(forward.length > 0 || canCancel) && (
                <Card className="flex flex-wrap items-center gap-3 p-4 sm:p-5">
                    <p className="text-sm font-medium text-ink">Siguiente paso</p>
                    {forward.map((item) => (
                        <PrimaryButton key={item.status} type="button" onClick={() => move(item.status)}>
                            {item.label}
                        </PrimaryButton>
                    ))}
                    {canCancel && (
                        <SecondaryButton type="button" onClick={() => setCancelling(true)}>
                            Cancelar pedido
                        </SecondaryButton>
                    )}
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="overflow-hidden lg:col-span-2">
                    <TableWrap>
                        <thead>
                            <tr>
                                <Th>Producto</Th>
                                <Th>Precio</Th>
                                <Th>Cantidad</Th>
                                <Th>Subtotal</Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {order.lines.map((line) => (
                                <tr key={line.id}>
                                    <Td>
                                        <p className="font-medium text-ink">{line.name}</p>
                                        {line.sku && <p className="font-mono text-xs text-ink-muted">{line.sku}</p>}
                                    </Td>
                                    <Td className="whitespace-nowrap text-ink-muted">{money(line.unitPrice, order.currency)}</Td>
                                    <Td className="text-ink-muted">{line.quantity}</Td>
                                    <Td className="whitespace-nowrap font-medium text-ink">{money(line.lineTotal, order.currency)}</Td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <Td colSpan={3} className="text-right text-sm font-semibold text-ink-muted">
                                    Total
                                </Td>
                                <Td className="whitespace-nowrap text-base font-extrabold text-ink">{money(order.total, order.currency)}</Td>
                            </tr>
                        </tfoot>
                    </TableWrap>
                    <p className="border-t border-line px-4 py-3 text-xs text-ink-muted sm:px-6">Los nombres, SKU y precios son los de la fecha del pedido: no cambian aunque el catálogo cambie.</p>
                </Card>

                <div className="space-y-6">
                    <Card className="space-y-2 p-4 text-sm sm:p-5">
                        <h2 className="text-base font-bold text-ink">Cliente</h2>
                        <p className="font-medium text-ink">{order.customerName}</p>
                        {order.customerPhone && <p className="text-ink-muted">{order.customerPhone}</p>}
                        {order.customerEmail && <p className="break-all text-ink-muted">{order.customerEmail}</p>}
                        {order.customerId && <p className="text-xs text-ink-muted">Vinculado a un cliente registrado.</p>}
                        {order.method === 'delivery' && (
                            <p className="flex items-start gap-1.5 pt-2 text-ink-muted">
                                <MapPin className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>
                                    {[order.address, order.city].filter(Boolean).join(', ')}
                                    {order.reference && <span className="block text-xs">{order.reference}</span>}
                                    {order.recipientName && <span className="block text-xs">Recibe: {order.recipientName}{order.recipientPhone ? ` · ${order.recipientPhone}` : ''}</span>}
                                </span>
                            </p>
                        )}
                        {!canEdit && order.notes && <p className="pt-2 text-xs text-ink-muted">Nota: {order.notes}</p>}
                        {order.cancelReason && <p className="pt-2 text-xs text-danger">Motivo de la cancelación: {order.cancelReason}</p>}
                    </Card>

                    {canEdit && (
                        <Card className="p-4 sm:p-5">
                            <h2 className="mb-3 text-base font-bold text-ink">Datos de entrega y notas</h2>
                            <DetailsForm order={order} />
                        </Card>
                    )}

                    <Card className="p-4 sm:p-5">
                        <h2 className="mb-3 text-base font-bold text-ink">Historial</h2>
                        <ol className="space-y-3 text-sm">
                            {[...order.history].reverse().map((change) => (
                                <li key={change.id}>
                                    <p className="font-medium text-ink">
                                        {statuses[change.to]}
                                        {change.from && <span className="font-normal text-ink-muted"> (desde «{statuses[change.from]}»)</span>}
                                    </p>
                                    <p className="text-xs text-ink-muted">
                                        {day(change.at)}
                                        {change.by && ` · ${change.by}`}
                                    </p>
                                    {change.reason && <p className="text-xs text-ink-muted">{change.reason}</p>}
                                </li>
                            ))}
                        </ol>
                    </Card>
                </div>
            </div>

            {cancelling && <CancelModal order={order} onClose={() => setCancelling(false)} />}
        </>
    );
}

Show.layout = (page) => <AppLayout>{page}</AppLayout>;
