import { Head, router, useForm } from '@inertiajs/react';
import { Package, Pencil, Plus, Power } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
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
import { money } from '@/config/orders';
import useAccess from '@/Hooks/useAccess';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';

function ProductModal({ product, locations, currency, onClose }) {
    const editing = Boolean(product?.id);
    const form = useForm({
        sku: product?.sku ?? '',
        name: product?.name ?? '',
        description: product?.description ?? '',
        category: product?.category ?? '',
        price: product?.price ?? '',
        location_ids: product?.locationIds ?? [],
    });
    const { data, setData, errors } = form;
    const toggleLocation = (id) => setData('location_ids', data.location_ids.includes(id) ? data.location_ids.filter((item) => item !== id) : [...data.location_ids, id]);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('catalog.update', product.id), options);
        else form.post(route('catalog.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar producto' : 'Nuevo producto'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-8">
                    <div>
                        <InputLabel htmlFor="product_name" value="Nombre" />
                        <TextInput id="product_name" className="mt-1" value={data.name} maxLength={160} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="product_price" value={`Precio (${currency})`} />
                            <TextInput id="product_price" type="number" min={0} step="0.01" inputMode="decimal" className="mt-1" value={data.price} onChange={(e) => setData('price', e.target.value)} invalid={Boolean(errors.price)} />
                            <InputError message={errors.price} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="product_sku" value="SKU (opcional)" />
                            <TextInput id="product_sku" className="mt-1" value={data.sku} maxLength={64} onChange={(e) => setData('sku', e.target.value)} invalid={Boolean(errors.sku)} />
                            <InputError message={errors.sku} className="mt-1" />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel htmlFor="product_category" value="Categoría (opcional)" />
                            <TextInput id="product_category" className="mt-1" value={data.category} maxLength={80} onChange={(e) => setData('category', e.target.value)} invalid={Boolean(errors.category)} />
                            <InputError message={errors.category} className="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel htmlFor="product_description" value="Descripción (opcional)" />
                        <Textarea id="product_description" rows={3} className="mt-1" value={data.description} maxLength={2000} onChange={(e) => setData('description', e.target.value)} />
                        <InputError message={errors.description} className="mt-1" />
                    </div>
                    {locations.length > 0 && (
                        <fieldset>
                            <legend className="text-sm font-medium text-ink">Sedes que lo ofrecen (opcional)</legend>
                            <p className="mt-0.5 text-xs text-ink-muted">Si no marcas ninguna, el producto se ofrece en todas las sedes.</p>
                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                {locations.map((location) => (
                                    <label key={location.id} className="flex cursor-pointer items-center gap-2 rounded-lg border border-field px-3 py-2 text-sm text-ink has-checked:border-primary/50 has-checked:bg-primary-soft/40">
                                        <input type="checkbox" className="size-4 accent-primary" checked={data.location_ids.includes(location.id)} onChange={() => toggleLocation(location.id)} />
                                        {location.name}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.location_ids ?? errors['location_ids.0']} className="mt-1" />
                        </fieldset>
                    )}
                    {editing && <p className="text-xs text-ink-muted">Cambiar el precio solo afecta a los pedidos nuevos: los pedidos ya registrados conservan el precio con el que se hicieron.</p>}
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Guardar cambios' : 'Crear producto'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Catálogo de productos: what the Workspace sells. Never deleted, only deactivated. */
export default function Index({ schemaPending, products, filters, perPageOptions, categories, locations, currency, total }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const canCreate = permissions.includes('create-products') && !schemaPending;
    const canEdit = permissions.includes('edit-products') && !schemaPending;
    const canToggle = permissions.includes('activate-products') && !schemaPending;
    const canAct = canEdit || canToggle;
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);

    const list = usePagedList({
        section: 'products',
        only: ['products', 'filters', 'categories'],
        meta: products.meta,
        perPageOptions,
        routeName: 'catalog.index',
        extra: { search: filters.search || undefined, category: filters.category || undefined, status: filters.status || undefined },
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
    const filtered = Boolean(filters.search || filters.category || filters.status);

    const toggle = async (product) => {
        const confirmed = await confirm({
            description: product.isActive ? `«${product.name}» dejará de ofrecerse en pedidos nuevos. Los pedidos que ya lo incluyen no cambian. Puede reactivarse.` : `«${product.name}» volverá a ofrecerse en pedidos nuevos.`,
            confirmLabel: product.isActive ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(product.isActive ? 'catalog.deactivate' : 'catalog.activate', product.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Catálogo" />

            <PageHeader title="Catálogo de productos" description="Lo que vende este Workspace: nombre, SKU, precio y las sedes que lo ofrecen. Nunca se borra: se desactiva.">
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

            {!schemaPending && (
                <Card className="overflow-hidden">
                    <div className="grid gap-3 border-b border-line p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
                        <SearchInput className="sm:col-span-2" value={search} onChange={setSearch} placeholder="Buscar por nombre o SKU" label="Buscar productos" />
                        <Select aria-label="Categoría" value={filters.category ?? ''} onChange={filter('category')} options={[{ value: '', label: 'Todas las categorías' }, ...categories.map((category) => ({ value: category, label: category }))]} />
                        <Select aria-label="Estado" value={filters.status ?? ''} onChange={filter('status')} options={[{ value: '', label: 'Todos los estados' }, { value: 'active', label: 'Activos' }, { value: 'inactive', label: 'Inactivos' }]} />
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
                        <TableWrap busy={list.loading}>
                            <thead>
                                <tr>
                                    <Th>Producto</Th>
                                    <Th>SKU</Th>
                                    <Th>Categoría</Th>
                                    <Th>Precio</Th>
                                    <Th>Sedes</Th>
                                    <Th>Estado</Th>
                                    {canAct && <Th className="text-center">Acciones</Th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {products.data.map((product) => (
                                    <tr key={product.id} className={product.isActive ? '' : 'opacity-60'}>
                                        <Td>
                                            <p className="font-semibold text-ink">{product.name}</p>
                                            {product.description && <p className="mt-0.5 max-w-md truncate text-xs text-ink-muted">{product.description}</p>}
                                        </Td>
                                        <Td className="whitespace-nowrap font-mono text-xs text-ink-muted">{product.sku ?? '—'}</Td>
                                        <Td className="text-ink-muted">{product.category ?? '—'}</Td>
                                        <Td className="whitespace-nowrap font-medium text-ink">{money(product.price, currency)}</Td>
                                        <Td className="text-ink-muted">{product.locationIds.length === 0 ? 'Todas' : `${product.locationIds.length} sede${product.locationIds.length === 1 ? '' : 's'}`}</Td>
                                        <Td className="whitespace-nowrap">
                                            <Badge tone={product.isActive ? 'green' : 'neutral'}>{product.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                        </Td>
                                        {canAct && (
                                            <Td>
                                                <div className="flex items-center justify-center gap-1">
                                                    {canEdit && <IconButton icon={Pencil} label="Editar" context={product.name} onClick={() => setEditing(product)} />}
                                                    {canToggle && <IconButton icon={Power} label={product.isActive ? 'Desactivar' : 'Activar'} context={product.name} tone={product.isActive ? 'danger' : 'primary'} onClick={() => toggle(product)} />}
                                                </div>
                                            </Td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </TableWrap>
                    )}
                    <Pagination noun="productos" {...list.paginationProps} />
                </Card>
            )}

            {editing && <ProductModal product={editing} locations={locations} currency={currency} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
