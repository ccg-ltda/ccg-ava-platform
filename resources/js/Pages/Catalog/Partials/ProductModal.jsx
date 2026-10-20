import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import { useConfirm } from '@/Components/ConfirmDialog';
import ImageField from '@/Components/ImageField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PriceInput from '@/Components/PriceInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchableSelect from '@/Components/SearchableSelect';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusSwitch from '@/Components/StatusSwitch';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { emptyStocks, stocksPayload } from '@/lib/inventory';

/** The category is chosen from the active categories of the Workspace; they are created in the «Categorías» tab of the catalog, never typed here. */
function CategoryField({ value, onChange, categories, error }) {
    return (
        <div>
            <InputLabel htmlFor="product_category" value="Categoría (opcional)" />
            {categories.length > 0 ? (
                <SearchableSelect
                    id="product_category"
                    className="mt-1"
                    value={value}
                    onChange={onChange}
                    options={[{ value: '', label: 'Sin categoría' }, ...categories.map((category) => ({ value: category.id, label: category.name }))]}
                    placeholder="Escribe para buscar o elige"
                    invalid={Boolean(error)}
                />
            ) : (
                <p className="mt-1 rounded-lg border border-dashed border-line px-3 py-2 text-sm text-ink-muted">
                    Aún no hay categorías. Créalas en la pestaña{' '}
                    <Link href={`${route('catalog.index')}?tab=categorias`} className="font-semibold text-accent-blue hover:underline">
                        Categorías
                    </Link>{' '}
                    del catálogo.
                </p>
            )}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** The first stock of a NEW product: one row per location (or the general one). Later quantities change with entries and adjustments, never from this form. */
function StockRows({ rows, onChange, errors }) {
    const set = (index, key, value) => onChange(rows.map((row, position) => (position === index ? { ...row, [key]: value } : row)));

    return (
        <div className="space-y-2" role="group" aria-label="Existencias iniciales por sede">
            <p className="text-xs text-ink-muted">Deja en blanco las sedes donde el producto no se maneja: ahí no aparecerá como disponible.</p>
            <div className="hidden grid-cols-[minmax(0,1fr)_7rem_7rem] gap-3 px-1 text-[11px] font-semibold tracking-wider text-ink-muted uppercase sm:grid">
                <span>Sede</span>
                <span>Existencia</span>
                <span>Stock mínimo</span>
            </div>
            {rows.map((row, index) => (
                <div key={row.location_id === '' ? 'general' : row.location_id} className="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-[minmax(0,1fr)_7rem_7rem] sm:items-center sm:gap-3">
                    <p className="text-sm font-semibold text-ink">{row.name}</p>
                    <div>
                        <InputLabel htmlFor={`stock_q_${index}`} value="Existencia" className="sm:sr-only" />
                        <TextInput id={`stock_q_${index}`} type="number" min={0} step={1} inputMode="numeric" className="mt-1 sm:mt-0" placeholder="Ej. 20" value={row.quantity} onChange={(event) => set(index, 'quantity', event.target.value)} invalid={Boolean(errors[`stocks.${index}.quantity`])} />
                    </div>
                    <div>
                        <InputLabel htmlFor={`stock_m_${index}`} value="Stock mínimo" className="sm:sr-only" />
                        <TextInput id={`stock_m_${index}`} type="number" min={0} step={1} inputMode="numeric" className="mt-1 sm:mt-0" placeholder="Ej. 5" value={row.min_quantity} onChange={(event) => set(index, 'min_quantity', event.target.value)} invalid={Boolean(errors[`stocks.${index}.min_quantity`])} />
                    </div>
                    <div className="sm:col-span-3">
                        <InputError message={errors[`stocks.${index}.quantity`] ?? errors[`stocks.${index}.min_quantity`]} />
                    </div>
                </div>
            ))}
            <InputError message={errors.stocks} />
        </div>
    );
}

/**
 * Create / edit a product. Every field has an example and a short help; the placeholders are never values. The state is a switch (green / red with the word), the
 * stock control is its own switch, and the first stock is only asked for a new product: later quantities change through entries and adjustments (with their
 * history). Each part appears only when the person has the permission and the inventory exists in this environment; the server validates everything again.
 */
export default function ProductModal({ product, locations, categories, currency, inventory, canActivate, onClose }) {
    const editing = Boolean(product?.id);
    const confirm = useConfirm();
    const ready = inventory.ready;
    const [file, setFile] = useState(null);
    const [removed, setRemoved] = useState(false);
    const [stocks, setStocks] = useState(() => emptyStocks(locations));
    const form = useForm({
        sku: product?.sku ?? '',
        name: product?.name ?? '',
        description: product?.description ?? '',
        category_id: product?.categoryId ?? '',
        category: product?.category ?? '',
        price: product?.price ?? '',
        cost: product?.cost ?? '',
        is_active: product?.isActive ?? true,
        tracks_inventory: product?.tracksInventory ?? false,
        location_ids: product?.locationIds ?? [],
    });
    const { data, setData, errors } = form;
    const toggleLocation = (id) => setData('location_ids', data.location_ids.includes(id) ? data.location_ids.filter((item) => item !== id) : [...data.location_ids, id]);

    const changeActive = async (next) => {
        if (!next && editing && product.openOrders > 0) {
            const confirmed = await confirm({
                description: `«${product.name}» está en ${product.openOrders} pedido${product.openOrders === 1 ? '' : 's'} en curso. Al desactivarlo no se ofrece en pedidos nuevos; esos pedidos y su inventario no cambian.`,
                confirmLabel: 'Desactivar',
            });

            if (!confirmed) return;
        }

        setData('is_active', next);
    };

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, forceFormData: true, onSuccess: onClose };

        form.transform((values) => {
            const payload = {
                ...values,
                // With the inventory the category is the id chosen from the list; the free text only exists before the migration.
                ...(ready ? { category: undefined } : {}),
                ...(ready && !canActivate ? { is_active: undefined } : {}),
                ...(ready && !inventory.canAdjust ? { tracks_inventory: undefined } : {}),
                ...(ready ? { image: file ?? undefined, remove_image: removed || undefined } : {}),
                ...(!editing && ready && values.tracks_inventory ? { stocks: stocksPayload(stocks) } : {}),
                ...(editing ? { _method: 'put' } : {}),
            };

            // A key left undefined is simply not sent (the person may not change it, or there is nothing to send).
            return Object.fromEntries(Object.entries(payload).filter(([, value]) => value !== undefined));
        });

        form.post(editing ? route('catalog.update', product.id) : route('catalog.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar producto' : 'Nuevo producto'}</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Los campos de ejemplo en gris solo te guían: no se guardan.</p>
                </header>
                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-5 sm:px-8">
                    {ready && (
                        <ImageField
                            id="product_image"
                            label="Imagen del producto (opcional)"
                            noun="imagen"
                            alt={`Imagen de ${data.name || 'el producto'}`}
                            file={file}
                            removed={removed}
                            currentUrl={product?.imageUrl}
                            onChoose={(chosen) => { setFile(chosen); setRemoved(false); }}
                            onRemove={() => { setFile(null); setRemoved(true); }}
                            error={errors.image}
                            hint={`PNG, JPG o WebP. Máximo ${inventory.config.maxKb / 1024} MB.`}
                        />
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputLabel htmlFor="product_name" value="Nombre" />
                            <TextInput id="product_name" className="mt-1" placeholder="Ej. Crema hidratante" value={data.name} maxLength={160} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="product_sku" value="SKU o referencia (opcional)" />
                            <TextInput id="product_sku" className="mt-1" placeholder="Ej. CRE-001" value={data.sku} maxLength={64} onChange={(e) => setData('sku', e.target.value)} invalid={Boolean(errors.sku)} />
                            <p className="mt-1 text-xs text-ink-muted">Código único dentro de este Workspace.</p>
                            <InputError message={errors.sku} className="mt-1" />
                        </div>
                        {ready ? (
                            <CategoryField value={data.category_id} onChange={(value) => setData('category_id', value)} categories={categories} error={errors.category_id} />
                        ) : (
                            <div>
                                <InputLabel htmlFor="product_category" value="Categoría (opcional)" />
                                <TextInput id="product_category" className="mt-1" placeholder="Ej. Cuidado facial" value={data.category} maxLength={80} onChange={(e) => setData('category', e.target.value)} invalid={Boolean(errors.category)} />
                                <InputError message={errors.category} className="mt-1" />
                            </div>
                        )}
                        <div>
                            <InputLabel htmlFor="product_price" value={`Precio de venta (${currency})`} />
                            <PriceInput id="product_price" className="mt-1" placeholder="Ej. 24.900" value={data.price} onChange={(value) => setData('price', value)} invalid={Boolean(errors.price)} />
                            <InputError message={errors.price} className="mt-1" />
                        </div>
                        {ready && (
                            <div>
                                <InputLabel htmlFor="product_cost" value={`Coste unitario (${currency}, opcional)`} />
                                <PriceInput id="product_cost" className="mt-1" placeholder="Ej. 12.000" value={data.cost} onChange={(value) => setData('cost', value)} invalid={Boolean(errors.cost)} />
                                <p className="mt-1 text-xs text-ink-muted">Lo que te cuesta a ti. No se muestra a los clientes.</p>
                                <InputError message={errors.cost} className="mt-1" />
                            </div>
                        )}
                    </div>

                    <div>
                        <InputLabel htmlFor="product_description" value="Descripción (opcional)" />
                        <Textarea id="product_description" rows={3} className="mt-1" placeholder="Describe brevemente el producto y sus características" value={data.description} maxLength={2000} onChange={(e) => setData('description', e.target.value)} />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    {ready && (
                        <div className="space-y-4 rounded-xl border border-line p-4">
                            <StatusSwitch
                                checked={data.is_active}
                                onChange={changeActive}
                                disabled={!canActivate}
                                description={canActivate ? 'Un producto inactivo no se ofrece en pedidos nuevos. Nunca se borra y su historial se conserva.' : 'Solo quien puede activar productos cambia este estado.'}
                            />
                            <InputError message={errors.is_active} />
                            <div className="border-t border-line pt-4">
                                <Toggle
                                    checked={data.tracks_inventory}
                                    onChange={(value) => setData('tracks_inventory', value)}
                                    disabled={!inventory.canAdjust}
                                    label="Controlar inventario"
                                    description={inventory.canAdjust ? 'Activa esta opción para controlar las existencias de este producto, configurar cantidades por sede y evitar ventas que superen el stock disponible' : 'Solo quien puede ajustar el inventario cambia esto.'}
                                />
                                <InputError message={errors.tracks_inventory} className="mt-1" />
                            </div>
                            {data.tracks_inventory && !editing && inventory.canAdjust && (
                                <div className="border-t border-line pt-4">
                                    <p className="mb-2 text-sm font-semibold text-ink">Existencias iniciales</p>
                                    <StockRows rows={stocks} onChange={setStocks} errors={errors} />
                                </div>
                            )}
                            {data.tracks_inventory && editing && (
                                <Alert tone="info">Las existencias de cada sede se cambian con entradas y ajustes desde el detalle del producto, para que quede su historial.</Alert>
                            )}
                        </div>
                    )}

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
