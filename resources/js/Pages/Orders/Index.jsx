import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { day, money } from '@/config/orders';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import OrderStatusBadge from './Partials/OrderStatusBadge';

function NewOrderModal({ form: options, methods, currency, onClose }) {
    const form = useForm({
        customer_id: '',
        customer_name: '',
        customer_phone: '',
        customer_email: '',
        delivery_method: 'pickup',
        delivery_address: '',
        delivery_city: '',
        delivery_reference: '',
        recipient_name: '',
        recipient_phone: '',
        location_id: '',
        notes: '',
        lines: [{ product_id: '', quantity: 1 }],
    });
    const { data, setData, errors } = form;
    const products = useMemo(() => options.products.filter((product) => !data.location_id || product.locationIds.length === 0 || product.locationIds.includes(Number(data.location_id))), [options.products, data.location_id]);
    const price = (id) => options.products.find((product) => product.id === Number(id))?.price ?? 0;
    // A preview only: the server reads the prices from the catalog and computes the real total.
    const estimate = data.lines.reduce((sum, line) => sum + price(line.product_id) * (Number(line.quantity) || 0), 0);
    const setLine = (index, patch) => setData('lines', data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const lineError = (index, field) => errors[`lines.${index}.${field}`];

    const pickCustomer = (value) => {
        const customer = options.customers.find((item) => item.id === Number(value));
        setData((current) => ({ ...current, customer_id: value, ...(customer ? { customer_name: customer.name, customer_phone: customer.phone ?? '', customer_email: customer.email ?? '' } : {}) }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((payload) => ({
            ...payload,
            customer_id: payload.customer_id || null,
            location_id: payload.location_id || null,
            lines: payload.lines.filter((line) => line.product_id).map((line) => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) })),
        }));
        form.post(route('orders.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">Nuevo pedido</h2>
                    <p className="mt-1 text-sm text-ink-muted">Los precios y el total los calcula Ava con el catálogo de este momento. El pedido queda registrado; Ava no gestiona el pago.</p>
                </header>
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-8">
                    <fieldset className="min-w-0 space-y-3 rounded-xl border border-line p-4">
                        <legend className="px-1 text-sm font-medium text-ink">Quién hace el pedido</legend>
                        {options.customers.length > 0 && (
                            <div>
                                <InputLabel htmlFor="order_customer" value="Cliente registrado (opcional)" />
                                <Select id="order_customer" className="mt-1" value={data.customer_id} onChange={pickCustomer} placeholder="Sin vincular a un cliente" options={[{ value: '', label: 'Sin vincular a un cliente' }, ...options.customers.map((customer) => ({ value: customer.id, label: customer.name }))]} />
                                <InputError message={errors.customer_id} className="mt-1" />
                            </div>
                        )}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <InputLabel htmlFor="order_name" value="Nombre" />
                                <TextInput id="order_name" className="mt-1" value={data.customer_name} maxLength={120} onChange={(e) => setData('customer_name', e.target.value)} invalid={Boolean(errors.customer_name)} />
                                <InputError message={errors.customer_name} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="order_phone" value="Teléfono (opcional)" />
                                <TextInput id="order_phone" type="tel" placeholder="+573001112233" className="mt-1" value={data.customer_phone} maxLength={32} onChange={(e) => setData('customer_phone', e.target.value)} invalid={Boolean(errors.customer_phone)} />
                                <InputError message={errors.customer_phone} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="order_email" value="Correo (opcional)" />
                                <TextInput id="order_email" type="email" className="mt-1" value={data.customer_email} maxLength={160} onChange={(e) => setData('customer_email', e.target.value)} invalid={Boolean(errors.customer_email)} />
                                <InputError message={errors.customer_email} className="mt-1" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset className="min-w-0 space-y-3">
                        <legend className="text-sm font-medium text-ink">Productos</legend>
                        {options.locations.length > 0 && (
                            <div className="max-w-sm">
                                <InputLabel htmlFor="order_location" value="Sede (opcional)" />
                                <Select id="order_location" className="mt-1" value={data.location_id} onChange={(value) => setData('location_id', value)} placeholder="Sin sede" options={[{ value: '', label: 'Sin sede' }, ...options.locations.map((location) => ({ value: location.id, label: location.name }))]} />
                                <InputError message={errors.location_id} className="mt-1" />
                            </div>
                        )}
                        {data.lines.map((line, index) => (
                            <div key={index} className="grid grid-cols-[minmax(0,1fr)_5rem_auto] items-start gap-2">
                                <div>
                                    <Select aria-label={`Producto ${index + 1}`} value={line.product_id} onChange={(value) => setLine(index, { product_id: value })} placeholder="Elige un producto" options={products.map((product) => ({ value: product.id, label: `${product.name} · ${money(product.price, currency)}` }))} invalid={Boolean(lineError(index, 'product_id'))} />
                                    <InputError message={lineError(index, 'product_id')} className="mt-1" />
                                </div>
                                <div>
                                    <TextInput type="number" min={1} max={9999} aria-label={`Cantidad ${index + 1}`} value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} invalid={Boolean(lineError(index, 'quantity'))} />
                                    <InputError message={lineError(index, 'quantity')} className="mt-1" />
                                </div>
                                {data.lines.length > 1 ? <IconButton icon={Trash2} label="Quitar producto" context={`${index + 1}`} tone="danger" onClick={() => setData('lines', data.lines.filter((_, i) => i !== index))} /> : <span className="size-9" />}
                            </div>
                        ))}
                        <InputError message={errors.lines} />
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <SecondaryButton type="button" className="gap-2" onClick={() => setData('lines', [...data.lines, { product_id: '', quantity: 1 }])}>
                                <Plus className="size-4" aria-hidden="true" />
                                Añadir producto
                            </SecondaryButton>
                            <p className="text-sm text-ink-muted">Total estimado: <span className="font-semibold text-ink">{money(estimate, currency)}</span></p>
                        </div>
                    </fieldset>

                    <fieldset className="min-w-0 space-y-3 rounded-xl border border-line p-4">
                        <legend className="px-1 text-sm font-medium text-ink">Entrega</legend>
                        <div className="max-w-sm">
                            <InputLabel htmlFor="order_method" value="Forma de entrega" />
                            <Select id="order_method" className="mt-1" value={data.delivery_method} onChange={(value) => setData('delivery_method', value)} options={Object.entries(methods).map(([value, label]) => ({ value, label }))} />
                            <InputError message={errors.delivery_method} className="mt-1" />
                        </div>
                        {data.delivery_method === 'delivery' && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="sm:col-span-2">
                                    <InputLabel htmlFor="order_address" value="Dirección de entrega" />
                                    <TextInput id="order_address" className="mt-1" value={data.delivery_address} maxLength={255} onChange={(e) => setData('delivery_address', e.target.value)} invalid={Boolean(errors.delivery_address)} />
                                    <InputError message={errors.delivery_address} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="order_city" value="Ciudad (opcional)" />
                                    <TextInput id="order_city" className="mt-1" value={data.delivery_city} maxLength={120} onChange={(e) => setData('delivery_city', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="order_reference" value="Referencias (opcional)" />
                                    <TextInput id="order_reference" className="mt-1" value={data.delivery_reference} maxLength={255} onChange={(e) => setData('delivery_reference', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="order_recipient" value="Quién recibe, si es otra persona (opcional)" />
                                    <TextInput id="order_recipient" className="mt-1" value={data.recipient_name} maxLength={120} onChange={(e) => setData('recipient_name', e.target.value)} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="order_recipient_phone" value="Teléfono de quien recibe (opcional)" />
                                    <TextInput id="order_recipient_phone" type="tel" placeholder="+573001112233" className="mt-1" value={data.recipient_phone} maxLength={32} onChange={(e) => setData('recipient_phone', e.target.value)} invalid={Boolean(errors.recipient_phone)} />
                                    <InputError message={errors.recipient_phone} className="mt-1" />
                                </div>
                            </div>
                        )}
                    </fieldset>

                    <div>
                        <InputLabel htmlFor="order_notes" value="Notas operativas (opcional)" />
                        <Textarea id="order_notes" rows={2} className="mt-1" value={data.notes} maxLength={500} onChange={(e) => setData('notes', e.target.value)} />
                        <InputError message={errors.notes} className="mt-1" />
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Registrar pedido
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Pedidos of E-commerce and Delivery: registered orders with their state; moving them along the flow happens inside each order. */
export default function Index({ schemaPending, orders, filters, perPageOptions, counts, statuses, methods, currency, form: formOptions, notifier }) {
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);
    const list = usePagedList({
        section: 'orders',
        only: ['orders', 'filters', 'counts'],
        meta: orders.meta,
        perPageOptions,
        routeName: 'orders.index',
        extra: { search: filters.search || undefined, status: filters.status || undefined, method: filters.method || undefined },
    });

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return undefined;
        }

        const timer = setTimeout(() => list.visit({ search: search || undefined }, { restart: true }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filter = (key) => (value) => list.visit({ [key]: value || undefined }, { restart: true });
    const filtered = Boolean(filters.search || filters.status || filters.method);
    const total = Object.values(counts).reduce((sum, value) => sum + Number(value), 0);
    const open = Object.entries(counts).filter(([status]) => !['delivered', 'cancelled'].includes(status)).reduce((sum, [, value]) => sum + Number(value), 0);

    return (
        <>
            <Head title="Pedidos" />

            <PageHeader title="Pedidos" description="Los pedidos registrados en este Workspace y su estado. Un pedido registrado no es un pedido pagado: Ava no gestiona pagos.">
                {formOptions && !schemaPending && (
                    <PrimaryButton type="button" onClick={() => setCreating(true)} className="gap-2" disabled={formOptions.products.length === 0}>
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo pedido
                    </PrimaryButton>
                )}
            </PageHeader>

            {schemaPending && (
                <Alert tone="warning">
                    Los pedidos todavía no están disponibles en este entorno: falta aplicar la última migración de la base de datos. Un administrador debe ejecutar <code>php artisan migrate</code>.
                </Alert>
            )}
            {formOptions && !schemaPending && formOptions.products.length === 0 && <Alert tone="info">Para registrar pedidos primero crea productos activos en el Catálogo.</Alert>}
            {!schemaPending && !notifier && <p className="text-xs text-ink-muted">Ava todavía no avisa a los clientes de sus pedidos: los cambios de estado solo se ven aquí.</p>}

            {!schemaPending && (
                <>
                    <div className="grid gap-3 sm:grid-cols-4">
                        {[['Total', total], ['En curso', open], ['Entregados', counts.delivered ?? 0], ['Cancelados', counts.cancelled ?? 0]].map(([label, value]) => (
                            <Card key={label} className="p-4">
                                <p className="text-xs font-semibold tracking-wide text-ink-muted uppercase">{label}</p>
                                <p className="mt-1 text-2xl font-extrabold text-ink tabular-nums">{value}</p>
                            </Card>
                        ))}
                    </div>

                    <Card className="overflow-hidden">
                        <div className="grid gap-3 border-b border-line p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
                            <SearchInput className="sm:col-span-2" value={search} onChange={setSearch} placeholder="Buscar por número o nombre del cliente" label="Buscar pedidos" />
                            <Select aria-label="Estado" value={filters.status ?? ''} onChange={filter('status')} options={[{ value: '', label: 'Todos los estados' }, ...Object.entries(statuses).map(([value, label]) => ({ value, label }))]} />
                            <Select aria-label="Entrega" value={filters.method ?? ''} onChange={filter('method')} options={[{ value: '', label: 'Toda entrega' }, ...Object.entries(methods).map(([value, label]) => ({ value, label }))]} />
                        </div>

                        {orders.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState icon={ShoppingCart} title={total === 0 ? 'Todavía no hay pedidos' : 'Ningún pedido coincide'} description={total === 0 ? 'Cuando registres el primero aparecerá aquí con su estado.' : 'Prueba con otros filtros o con otra búsqueda.'}>
                                    {filtered && <PrimaryButton type="button" onClick={() => { setSearch(''); router.get(route('orders.index')); }}>Quitar filtros</PrimaryButton>}
                                </EmptyState>
                            </div>
                        ) : (
                            <TableWrap busy={list.loading}>
                                <thead>
                                    <tr>
                                        <Th>Pedido</Th>
                                        <Th>Cliente</Th>
                                        <Th>Entrega</Th>
                                        <Th>Total</Th>
                                        <Th>Estado</Th>
                                        <Th>Fecha</Th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {orders.data.map((order) => (
                                        <tr key={order.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                            <Td>
                                                <Link href={route('orders.show', order.id)} className="font-mono text-sm font-bold text-ink hover:text-primary">
                                                    #{order.number}
                                                </Link>
                                            </Td>
                                            <Td className="font-medium text-ink">{order.customerName}</Td>
                                            <Td className="text-ink-muted">
                                                {order.methodLabel}
                                                {order.location && <span className="block text-xs">{order.location}</span>}
                                            </Td>
                                            <Td className="whitespace-nowrap font-medium text-ink">{money(order.total, order.currency)}</Td>
                                            <Td className="whitespace-nowrap">
                                                <OrderStatusBadge status={order.status} label={order.statusLabel} />
                                            </Td>
                                            <Td className="whitespace-nowrap text-ink-muted">{day(order.createdAt)}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </TableWrap>
                        )}
                        <Pagination noun="pedidos" {...list.paginationProps} />
                    </Card>
                </>
            )}

            {creating && formOptions && <NewOrderModal form={formOptions} methods={methods} currency={currency} onClose={() => setCreating(false)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
