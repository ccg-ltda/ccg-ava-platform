import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, History, MapPin, PackagePlus, Pencil, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import { money } from '@/config/orders';
import useFormatDateTime from '@/Hooks/useFormatDateTime';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import { MOVEMENT_TONES, STATES, levelOf, signed, units } from '@/lib/inventory';
import ProductModal from './Partials/ProductModal';
import StockDialog from './Partials/StockDialog';
import { ProductThumb } from './Partials/StockInfo';

/** The places whose stock this product can have: every active location, or the general stock when the Workspace has none. */
const placesOf = (product, locations) => {
    const rows = product.stock?.stocks ?? [];
    const place = (locationId, name) => {
        const row = rows.find((item) => (item.locationId ?? null) === (locationId ?? null));

        return { locationId, name, has: Boolean(row), quantity: row?.quantity ?? 0, min: row?.min ?? 0, level: row?.level ?? null };
    };

    return locations.length === 0 ? [place(null, 'Inventario general')] : locations.map((location) => place(location.id, location.name));
};

function Movements({ product, movements, movementPlace, perPageOptions }) {
    const formatDateTime = useFormatDateTime();
    const list = usePagedList({ section: 'movements', only: ['movements'], meta: movements.meta, perPageOptions, routeName: 'catalog.show', routeParams: product.id, extra: { movement_place: movementPlace || undefined } });

    if (movements.data.length === 0) {
        return <EmptyState icon={History} title="Todavía no hay movimientos" description="Cada entrada, ajuste, venta y cancelación de este producto aparecerá aquí con la cantidad antes y después." />;
    }

    return (
        <>
            {/* Phones and small tablets: one card per movement instead of a seven-column table. */}
            <ul className="divide-y divide-line lg:hidden" aria-label="Movimientos">
                {movements.data.map((movement) => (
                    <li key={movement.id} className="space-y-1.5 px-4 py-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Badge tone={MOVEMENT_TONES[movement.type] ?? 'neutral'}>{movement.typeLabel}</Badge>
                            <span className={`text-base font-bold tabular-nums ${movement.change > 0 ? 'text-accent-green' : 'text-ink'}`}>{signed(movement.change)}</span>
                        </div>
                        <p className="text-xs text-ink-muted">
                            {movement.location} · {movement.before.toLocaleString('es')} → <span className="font-semibold text-ink">{movement.after.toLocaleString('es')}</span> · {formatDateTime(movement.createdAt)}
                        </p>
                        <p className="text-xs break-words text-ink-muted">
                            {movement.user ?? 'Sistema'} ·{' '}
                            {movement.orderId ? (
                                <Link href={route('orders.show', movement.orderId)} className="font-semibold text-accent-blue hover:underline">
                                    Pedido #{movement.orderNumber}
                                </Link>
                            ) : (
                                (movement.reason ?? 'sin motivo')
                            )}
                        </p>
                    </li>
                ))}
            </ul>

            <div className="hidden lg:block">
            <TableWrap busy={list.loading}>
                <thead>
                    <tr>
                        <Th>Fecha</Th>
                        <Th>Movimiento</Th>
                        <Th>Sede</Th>
                        <Th className="text-right">Cambio</Th>
                        <Th className="text-right">Antes → después</Th>
                        <Th>Responsable</Th>
                        <Th>Motivo o pedido</Th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {movements.data.map((movement) => (
                        <tr key={movement.id}>
                            <Td className="whitespace-nowrap text-ink-muted">{formatDateTime(movement.createdAt)}</Td>
                            <Td>
                                <Badge tone={MOVEMENT_TONES[movement.type] ?? 'neutral'}>{movement.typeLabel}</Badge>
                            </Td>
                            <Td className="text-ink-muted">{movement.location}</Td>
                            <Td className={`text-right font-semibold tabular-nums ${movement.change > 0 ? 'text-accent-green' : 'text-ink'}`}>{signed(movement.change)}</Td>
                            <Td className="whitespace-nowrap text-right text-ink-muted tabular-nums">
                                {movement.before.toLocaleString('es')} → <span className="font-semibold text-ink">{movement.after.toLocaleString('es')}</span>
                            </Td>
                            <Td className="text-ink-muted">{movement.user ?? 'Sistema'}</Td>
                            <Td className="max-w-xs text-ink-muted">
                                {movement.orderId ? (
                                    <Link href={route('orders.show', movement.orderId)} className="font-semibold text-accent-blue hover:underline">
                                        Pedido #{movement.orderNumber}
                                    </Link>
                                ) : (
                                    <span className="break-words">{movement.reason ?? '—'}</span>
                                )}
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </TableWrap>
            </div>
            <Pagination noun="movimientos" {...list.paginationProps} />
        </>
    );
}

/** One product: its picture and data, the stock of each location (with entries, adjustments and minimums) and the history of its movements. */
export default function Show({ product, locations, categories, movements, movementPlace, perPageOptions, currency, inventory, can }) {
    const confirm = useConfirm();
    const [dialog, setDialog] = useState(null); // { mode, place }
    const [editing, setEditing] = useState(false);
    const places = placesOf(product, locations);
    const configured = places.filter((place) => place.has).length;
    const canControl = inventory.ready && inventory.canAdjust;

    const filterMovements = (place) => router.get(route('catalog.show', product.id), { movement_place: place || undefined }, { only: ['movements', 'movementPlace'], preserveScroll: true, preserveState: true, replace: true });

    // Turning the control on never touches the quantities; turning it off keeps every quantity and the whole history.
    const control = async (next) => {
        if (!next) {
            const confirmed = await confirm({
                description: `«${product.name}» dejará de bloquearse por falta de stock. Sus existencias y su historial se conservan y puedes volver a activar el control.`,
                confirmLabel: 'Desactivar control',
            });

            if (!confirmed) return;
        }

        router.put(route('catalog.stock.control', product.id), { tracks_inventory: next }, { preserveScroll: true });
    };

    return (
        <>
            <Head title={product.name} />

            <Link href={route('catalog.index')} className="inline-flex items-center gap-2 text-sm font-semibold text-accent-blue hover:underline">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Catálogo
            </Link>

            <PageHeader title={product.name} description={[product.sku, product.category].filter(Boolean).join(' · ') || 'Sin SKU ni categoría'}>
                <Badge tone={product.isActive ? 'green' : 'red'}>{product.isActive ? 'Activo' : 'Inactivo'}</Badge>
                {can.edit && (
                    <PrimaryButton type="button" onClick={() => setEditing(true)} className="gap-2">
                        <Pencil className="size-4" aria-hidden="true" />
                        Editar
                    </PrimaryButton>
                )}
            </PageHeader>

            {inventory.ready && inventory.canView && (
                <nav aria-label="Secciones del producto" className="flex flex-wrap gap-2 text-sm font-semibold">
                    {[['datos', 'Datos'], ['existencias', 'Existencias por sede'], ['historial', 'Historial']].map(([id, label]) => (
                        <a key={id} href={`#${id}`} className="rounded-full border border-line px-3 py-1 text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                            {label}
                        </a>
                    ))}
                </nav>
            )}

            {!inventory.ready && <Alert tone="info">El inventario todavía no está disponible en este entorno: falta aplicar la última migración de la base de datos.</Alert>}

            <div className="grid gap-6 lg:grid-cols-3">
                <Card id="datos" className="min-w-0 scroll-mt-24 space-y-4 p-5 sm:p-6">
                    <div className="grid aspect-[4/3] w-full place-items-center overflow-hidden rounded-xl border border-line bg-canvas">
                        {product.imageUrl ? <img src={product.imageUrl} alt={`Imagen de ${product.name}`} className="size-full object-contain" /> : <ProductThumb product={product} size="size-28" iconSize="size-10" />}
                    </div>
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-ink-muted">Precio de venta</dt>
                            <dd className="font-semibold text-ink">{money(product.price, currency)}</dd>
                        </div>
                        {product.cost !== null && product.cost !== undefined && (
                            <div className="flex justify-between gap-3">
                                <dt className="text-ink-muted">Coste unitario</dt>
                                <dd className="font-semibold text-ink">{money(product.cost, currency)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between gap-3">
                            <dt className="text-ink-muted">Sedes que lo ofrecen</dt>
                            <dd className="text-right text-ink">{product.locationIds.length === 0 ? 'Todas' : locations.filter((location) => product.locationIds.includes(location.id)).map((location) => location.name).join(', ') || `${product.locationIds.length}`}</dd>
                        </div>
                        {product.openOrders > 0 && (
                            <div className="flex justify-between gap-3">
                                <dt className="text-ink-muted">En pedidos en curso</dt>
                                <dd className="text-ink">{product.openOrders}</dd>
                            </div>
                        )}
                    </dl>
                    {product.description && <p className="border-t border-line pt-3 text-sm break-words text-ink-muted">{product.description}</p>}
                </Card>

                <div className="min-w-0 space-y-6 lg:col-span-2">
                    {inventory.ready && inventory.canView && (
                        <Card id="existencias" className="scroll-mt-24 overflow-hidden">
                            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-line p-4 sm:p-6">
                                <div>
                                    <h2 className="flex items-center gap-2 text-base font-bold text-ink">
                                        <MapPin className="size-4 text-accent-blue" aria-hidden="true" />
                                        Existencias por {locations.length === 0 ? 'inventario' : 'sede'}
                                    </h2>
                                    <p className="mt-0.5 text-sm text-ink-muted">Cada sede tiene su propia cantidad: no se suman como si estuvieran disponibles en una sola.</p>
                                </div>
                                {product.tracksInventory && canControl && (
                                    <button type="button" onClick={() => control(false)} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                                        Desactivar control de inventario
                                    </button>
                                )}
                            </div>

                            {!product.tracksInventory ? (
                                <div className="p-6">
                                    <EmptyState icon={SlidersHorizontal} title="No controlado" description="Activa esta opción para controlar las existencias de este producto, configurar cantidades por sede y evitar ventas que superen el stock disponible">
                                        {canControl && (
                                            <PrimaryButton type="button" onClick={() => control(true)}>
                                                Activar control de inventario
                                            </PrimaryButton>
                                        )}
                                    </EmptyState>
                                </div>
                            ) : (
                                <>
                                    {configured === 0 && (
                                        <Alert tone="warning" className="m-4 sm:mx-6">
                                            Falta registrar las existencias iniciales{locations.length === 0 ? '' : ' de cada sede'}: hasta entonces el producto no se puede pedir. Usa «Configurar existencias iniciales».
                                        </Alert>
                                    )}
                                <ul className="divide-y divide-line" aria-label="Existencias por sede">
                                    {places.map((place) => {
                                        const level = levelOf(place.level);

                                        return (
                                            <li key={place.locationId ?? 'general'} className="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-4 sm:px-6">
                                                <div className="min-w-[10rem] flex-1">
                                                    <p className="text-sm font-semibold text-ink">{place.name}</p>
                                                    {place.has ? (
                                                        <p className="text-xs text-ink-muted">Stock mínimo: {place.min > 0 ? units(place.min) : 'sin aviso'}</p>
                                                    ) : (
                                                        <p className="text-xs text-ink-muted">{locations.length === 0 ? 'Aún sin existencias registradas.' : 'Aún sin existencias registradas en esta sede: no se puede pedir aquí.'}</p>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {place.has ? (
                                                        <>
                                                            <span className="text-lg font-bold text-ink tabular-nums">{place.quantity > 0 ? place.quantity.toLocaleString('es') : STATES.empty}</span>
                                                            {level && place.quantity > 0 && <Badge tone={level.tone}>{level.label}</Badge>}
                                                        </>
                                                    ) : (
                                                        <Badge tone="amber">{STATES.unconfigured}</Badge>
                                                    )}
                                                </div>
                                                {inventory.canAdjust && (
                                                    <div className="flex flex-wrap items-center gap-1">
                                                        <button type="button" onClick={() => setDialog({ mode: 'receive', place })} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                                                            <PackagePlus className="size-4" aria-hidden="true" />
                                                            {place.has ? 'Registrar entrada' : 'Configurar existencias iniciales'}
                                                        </button>
                                                        {place.has && (
                                                            <>
                                                                <button type="button" onClick={() => setDialog({ mode: 'adjust', place })} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                                                                    Ajustar existencias
                                                                </button>
                                                                <button type="button" onClick={() => setDialog({ mode: 'minimum', place })} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                                                                    Stock mínimo
                                                                </button>
                                                                {inventory.canView && (
                                                                    <Link href={`${route('catalog.show', product.id)}?movement_place=${place.locationId ?? 'general'}#historial`} preserveScroll className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                                                                        Movimientos
                                                                    </Link>
                                                                )}
                                                            </>
                                                        )}
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                                </>
                            )}
                        </Card>
                    )}

                    {inventory.ready && inventory.canView && movements && (
                        <Card id="historial" className="scroll-mt-24 overflow-hidden">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                                <h2 className="flex items-center gap-2 text-base font-bold text-ink">
                                    <History className="size-4 text-accent-blue" aria-hidden="true" />
                                    Historial de movimientos
                                </h2>
                                <Select
                                    aria-label="Sede del historial"
                                    className="w-full sm:w-56"
                                    value={movementPlace ?? ''}
                                    onChange={filterMovements}
                                    options={[{ value: '', label: locations.length === 0 ? 'Todo el historial' : 'Todas las sedes' }, ...(locations.length === 0 ? [{ value: 'general', label: 'Inventario general' }] : locations.map((location) => ({ value: String(location.id), label: location.name })))]}
                                />
                            </div>
                            <Movements product={product} movements={movements} movementPlace={movementPlace} perPageOptions={perPageOptions} />
                        </Card>
                    )}

                    {inventory.ready && !inventory.canView && <Alert tone="info">Tu rol no incluye consultar el inventario: aquí solo se muestran los datos del producto.</Alert>}
                </div>
            </div>

            {dialog && <StockDialog mode={dialog.mode} product={product} place={dialog.place} onClose={() => setDialog(null)} />}
            {editing && <ProductModal product={product} locations={locations} categories={categories} currency={currency} inventory={inventory} canActivate={can.activate} onClose={() => setEditing(false)} />}
        </>
    );
}

Show.layout = (page) => <AppLayout>{page}</AppLayout>;

