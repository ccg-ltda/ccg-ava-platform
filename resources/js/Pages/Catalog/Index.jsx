import { Head, Link, router } from '@inertiajs/react';
import { BellRing, Boxes, MapPin, Package, PackageX, Pencil, Plus, Settings2, SlidersHorizontal, Tag, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import Select from '@/Components/Select';
import StatusSwitch from '@/Components/StatusSwitch';
import { TableWrap, Td, Th } from '@/Components/Table';
import Tabs from '@/Components/Tabs';
import { money } from '@/config/orders';
import useAccess from '@/Hooks/useAccess';
import usePagedList from '@/Hooks/usePagedList';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import { configuredPlaces, offeredLocations } from '@/lib/inventory';
import CategoriesPanel from './Partials/CategoriesPanel';
import ProductModal from './Partials/ProductModal';
import StockInfo, { ProductThumb } from './Partials/StockInfo';

const AVAILABILITY = [
    { value: '', label: 'Toda disponibilidad' },
    { value: 'ok', label: 'Con existencias' },
    { value: 'low', label: 'Stock bajo' },
    { value: 'out', label: 'Agotados' },
    { value: 'unconfigured', label: 'Sin configurar' },
    { value: 'untracked', label: 'Sin control de inventario' },
];

/** A figure of the compact summary. */
function Figure({ icon: Icon, label, value, tone = 'text-ink', hint }) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-line bg-card px-4 py-3">
            <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-accent-blue">
                <Icon className="size-4" aria-hidden="true" />
            </span>
            <div className="min-w-0">
                <p className={`text-xl leading-tight font-bold tabular-nums ${tone}`}>{value.toLocaleString('es')}</p>
                <p className="text-xs leading-snug text-ink-muted">{label}</p>
                {hint && <p className="text-[11px] leading-snug text-ink-muted/80">{hint}</p>}
            </div>
        </div>
    );
}

/** The state of a product with the word (a deactivated product is not an out-of-stock one). */
function StateBadge({ product }) {
    return <Badge tone={product.isActive ? 'green' : 'red'}>{product.isActive ? 'Activo' : 'Inactivo'}</Badge>;
}

/** Gestionar (the product detail: data, stock per sede, entries, adjustments, history) / Editar, always visible and reachable with the keyboard. */
function RowActions({ product, canEdit, onEdit }) {
    return (
        <div className="flex items-center justify-end gap-1 whitespace-nowrap">
            <Link href={route('catalog.show', product.id)} aria-label={`Gestionar ${product.name}`} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                <Settings2 className="size-4" aria-hidden="true" />
                Gestionar
            </Link>
            {canEdit && (
                <button type="button" onClick={() => onEdit(product)} aria-label={`Editar ${product.name}`} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                    <Pencil className="size-4" aria-hidden="true" />
                    Editar
                </button>
            )}
        </div>
    );
}

/** Catálogo de productos and its inventory: what the Workspace sells and how much of it is where. Never deleted, only deactivated. */
export default function Index({ schemaPending, categoryAdmin, products, filters, perPageOptions, categories, locations, currency, total, inventory }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const canCreate = permissions.includes('create-products') && !schemaPending;
    const canEdit = permissions.includes('edit-products') && !schemaPending;
    const canToggle = permissions.includes('activate-products') && !schemaPending;
    const [editing, setEditing] = useState(null);
    const [tab, selectTab] = useUrlTab(['catalogo', 'categorias']);
    const tabs = [{ id: 'catalogo', label: 'Catálogo', icon: Package, count: total }, { id: 'categorias', label: 'Categorías', icon: Tag, count: categoryAdmin?.data.length }];
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);
    const showStock = Boolean(inventory?.canView);
    const categoryOptions = inventory.ready ? categories.map((category) => ({ value: String(category.id), label: category.name })) : categories.map((category) => ({ value: category, label: category }));

    const list = usePagedList({
        section: 'products',
        only: ['products', 'filters', 'categories', 'inventory', 'categoryAdmin'],
        meta: products.meta,
        perPageOptions,
        routeName: 'catalog.index',
        extra: { search: filters.search || undefined, category: filters.category || undefined, status: filters.status || undefined, availability: filters.availability || undefined, place: filters.place || undefined },
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
    const filtered = Boolean(filters.search || filters.category || filters.status || filters.availability);
    const placeOptions = [{ value: '', label: 'Todas las sedes' }, ...locations.map((location) => ({ value: String(location.id), label: location.name })), ...(locations.length === 0 ? [{ value: 'general', label: 'Inventario general' }] : [])];

    const toggle = async (product) => {
        const confirmed = await confirm({
            description: product.isActive
                ? `«${product.name}» dejará de ofrecerse en pedidos nuevos.${product.openOrders > 0 ? ` Está en ${product.openOrders} pedido${product.openOrders === 1 ? '' : 's'} en curso: no cambian.` : ' Los pedidos que ya lo incluyen no cambian.'} Puede reactivarse.`
                : `«${product.name}» volverá a ofrecerse en pedidos nuevos.`,
            confirmLabel: product.isActive ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(product.isActive ? 'catalog.deactivate' : 'catalog.activate', product.id), {}, { preserveScroll: true });
    };

    const catalogPanel = (
        <>
            {!schemaPending && showStock && inventory.summary && (
                <section aria-label="Resumen del inventario" className="space-y-2">
                    <p className="flex flex-wrap items-center gap-1.5 text-sm text-ink-muted">
                        <MapPin className="size-4" aria-hidden="true" />
                        Resumen de <strong className="text-ink">{inventory.placeLabel}</strong>
                        {filters.place === '' && locations.length > 0 && <span>(cada sede se cuenta por separado)</span>}
                    </p>
                    <div className="grid grid-cols-2 gap-3 xl:grid-cols-5">
                        <Figure icon={Boxes} label="Productos con control de inventario" value={inventory.summary.tracked} />
                        <Figure icon={Package} label="Unidades en existencia" value={inventory.summary.units} hint={filters.place === '' && locations.length > 0 ? 'Suma de todas las sedes' : undefined} />
                        <Figure icon={TriangleAlert} label="Con stock bajo" value={inventory.summary.low} tone={inventory.summary.low > 0 ? 'text-accent-amber' : 'text-ink'} />
                        <Figure icon={PackageX} label="Agotados" hint="Con existencia registrada en cero" value={inventory.summary.out} tone={inventory.summary.out > 0 ? 'text-danger' : 'text-ink'} />
                        <Figure icon={SlidersHorizontal} label="Sin configurar" hint="Control activo, sin existencias registradas" value={inventory.summary.unconfigured} tone={inventory.summary.unconfigured > 0 ? 'text-accent-amber' : 'text-ink'} />
                    </div>
                </section>
            )}

            {!schemaPending && (
                <Card className="overflow-hidden">
                    <div className={`grid gap-3 border-b border-line p-4 sm:grid-cols-2 sm:p-6 ${showStock ? 'xl:grid-cols-[minmax(0,1.3fr)_repeat(4,minmax(9.5rem,1fr))]' : 'xl:grid-cols-[minmax(0,1.3fr)_repeat(2,minmax(9.5rem,1fr))]'}`}>
                        <SearchInput className="sm:col-span-2 xl:col-span-1" value={search} onChange={setSearch} placeholder="Buscar por nombre o SKU" label="Buscar productos" />
                        {showStock && <Select aria-label="Sede consultada" value={filters.place ?? ''} onChange={filter('place')} options={placeOptions} />}
                        <Select aria-label="Categoría" value={filters.category ?? ''} onChange={filter('category')} options={[{ value: '', label: 'Todas las categorías' }, ...categoryOptions]} />
                        <Select aria-label="Estado" value={filters.status ?? ''} onChange={filter('status')} options={[{ value: '', label: 'Todos los estados' }, { value: 'active', label: 'Activos' }, { value: 'inactive', label: 'Inactivos' }]} />
                        {showStock && <Select aria-label="Disponibilidad" value={filters.availability ?? ''} onChange={filter('availability')} options={AVAILABILITY} />}
                    </div>

                    {products.data.length === 0 ? (
                        <div className="p-6">
                            <EmptyState icon={Package} title={total === 0 ? 'Todavía no hay productos' : 'Ningún producto coincide'} description={total === 0 ? 'Crea el primero para poder usarlo en los pedidos.' : 'Prueba con otros filtros o con otra búsqueda.'}>
                                {filtered ? (
                                    <PrimaryButton type="button" onClick={() => { setSearch(''); router.get(route('catalog.index')); }}>Quitar filtros</PrimaryButton>
                                ) : (
                                    canCreate && <PrimaryButton type="button" onClick={() => setEditing({})}>Nuevo producto</PrimaryButton>
                                )}
                            </EmptyState>
                        </div>
                    ) : (
                        <>
                            {/* Phones and small tablets: one card per product, nothing scrolls sideways. */}
                            <ul className="divide-y divide-line lg:hidden" aria-label="Productos">
                                {products.data.map((product) => (
                                    <li key={product.id} className={`space-y-3 p-4 ${product.isActive ? '' : 'bg-canvas/50'}`}>
                                        <div className="flex gap-3">
                                            <ProductThumb product={product} size="size-16" iconSize="size-6" />
                                            <div className="min-w-0 flex-1">
                                                <p className="font-semibold break-words text-ink">{product.name}</p>
                                                <p className="text-xs text-ink-muted">{[product.sku, product.category].filter(Boolean).join(' · ') || 'Sin SKU ni categoría'}</p>
                                                {showStock && configuredPlaces(product.stock) && <p className="text-xs text-ink-muted">{configuredPlaces(product.stock)}</p>}
                                                <p className="mt-1 text-sm font-semibold text-ink">{money(product.price, currency)}</p>
                                            </div>
                                            <span className="self-start">
                                                <StateBadge product={product} />
                                                {product.needsLocation && <Badge tone="amber" className="ml-1">Sin sede asignada</Badge>}
                                            </span>
                                        </div>
                                        {showStock && <StockInfo stock={product.stock} locations={offeredLocations(product, locations)} />}
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            {canToggle ? (
                                                <button type="button" onClick={() => toggle(product)} className="text-xs font-semibold text-ink-muted underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                                                    {product.isActive ? 'Desactivar' : 'Activar'}
                                                </button>
                                            ) : (
                                                <span />
                                            )}
                                            <RowActions product={product} canEdit={canEdit} onEdit={setEditing} />
                                        </div>
                                    </li>
                                ))}
                            </ul>

                            <div className="hidden lg:block">
                                <TableWrap busy={list.loading}>
                                    <thead>
                                        <tr>
                                            <Th>Producto</Th>
                                            <Th>Categoría</Th>
                                            <Th>Precio</Th>
                                            {showStock && <Th>{filters.place === '' ? 'Existencias' : 'Existencias de la sede'}</Th>}
                                            <Th>Estado</Th>
                                            <Th className="text-right">Acciones</Th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-line">
                                        {products.data.map((product) => (
                                            <tr key={product.id} className={`transition-colors duration-150 hover:bg-canvas/70 focus-within:bg-canvas/70 ${product.isActive ? '' : 'bg-canvas/40'}`}>
                                                <Td>
                                                    <div className="flex items-center gap-3">
                                                        <ProductThumb product={product} />
                                                        <div className="min-w-0">
                                                            <Link href={route('catalog.show', product.id)} className="font-semibold text-ink hover:text-accent-blue focus-visible:outline-2 focus-visible:outline-primary">
                                                                {product.name}
                                                            </Link>
                                                            <p className="font-mono text-xs text-ink-muted">{product.sku ?? 'Sin SKU'}</p>
                                                            {showStock && configuredPlaces(product.stock) && <p className="text-xs text-ink-muted">{configuredPlaces(product.stock)}</p>}
                                                        </div>
                                                    </div>
                                                </Td>
                                                <Td className="text-ink-muted">{product.category ?? '—'}</Td>
                                                <Td className="whitespace-nowrap font-medium text-ink">{money(product.price, currency)}</Td>
                                                {showStock && (
                                                    <Td>
                                                        <StockInfo stock={product.stock} locations={offeredLocations(product, locations)} />
                                                    </Td>
                                                )}
                                                <Td className="whitespace-nowrap">
                                                    {canToggle ? <StatusSwitch checked={product.isActive} onChange={() => toggle(product)} aria-label={`${product.isActive ? 'Desactivar' : 'Activar'} ${product.name}`} /> : <StateBadge product={product} />}
                                                </Td>
                                                <Td>
                                                    <RowActions product={product} canEdit={canEdit} onEdit={setEditing} />
                                                </Td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </TableWrap>
                            </div>
                        </>
                    )}
                    <Pagination noun="productos" {...list.paginationProps} />
                </Card>
            )}
        </>
    );

    return (
        <>
            <Head title="Catálogo" />

            <PageHeader title="Catálogo de productos" description="Lo que vende este Workspace y cuánto hay de cada producto en cada sede. Nunca se borra: se desactiva.">
                {permissions.includes('view-catalog-reminders') && (
                    <Link href={route('catalog.reminders.index')} className="inline-flex items-center gap-2 rounded-lg border border-field px-3.5 py-2 text-sm font-semibold text-ink hover:bg-canvas">
                        <BellRing className="size-4" aria-hidden="true" />
                        Recordatorios
                    </Link>
                )}
                {canCreate && (
                    <PrimaryButton type="button" onClick={() => setEditing({})} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo producto
                    </PrimaryButton>
                )}
            </PageHeader>

            {schemaPending && (
                <Alert tone="warning">
                    El catálogo todavía no está disponible en este entorno: falta aplicar la última migración de la base de datos. Un administrador debe ejecutar <code>php artisan migrate</code>.
                </Alert>
            )}

            {!schemaPending && !inventory.ready && (
                <Alert tone="info">El inventario todavía no está disponible en este entorno: falta aplicar la última migración de la base de datos. El catálogo sigue funcionando como siempre.</Alert>
            )}

            {!schemaPending && (categoryAdmin ? <Tabs tabs={tabs} selectedIndex={tab} onChange={selectTab}>{catalogPanel}<CategoriesPanel admin={categoryAdmin} /></Tabs> : catalogPanel)}

            {editing && <ProductModal product={editing} locations={locations} categories={categories} currency={currency} inventory={inventory} canActivate={canToggle} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
