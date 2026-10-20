import { useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { units } from '@/lib/inventory';

const COPY = {
    receive: { title: 'Registrar entrada', field: 'quantity', label: 'Unidades que entran', placeholder: 'Ej. 20', help: 'Se suman a la existencia actual y queda en el historial.', submit: 'Registrar entrada', reasonLabel: 'Motivo o referencia (opcional)', reasonPlaceholder: 'Ej. Compra a proveedor, factura 1234' },
    adjust: { title: 'Ajustar existencia', field: 'quantity', label: 'Existencia real', placeholder: 'Ej. 18', help: 'Escribe la cantidad que de verdad hay. La diferencia queda en el historial con tu motivo.', submit: 'Guardar ajuste', reasonLabel: 'Motivo del ajuste', reasonPlaceholder: 'Ej. Conteo físico, producto dañado' },
    minimum: { title: 'Stock mínimo', field: 'min_quantity', label: 'Stock mínimo', placeholder: 'Ej. 5', help: 'Cuando la existencia llegue a esta cantidad o baje de ella, el producto se marca con stock bajo. 0 desactiva el aviso.', submit: 'Guardar mínimo' },
};

/** Entry, adjustment or minimum of ONE location of a product. Every change goes through the server's inventory service and leaves its movement. */
export default function StockDialog({ mode, product, place, onClose }) {
    const copy = COPY[mode];
    const form = useForm({ [copy.field]: mode === 'adjust' ? place.quantity : mode === 'minimum' ? place.min : '', reason: '', location_id: place.locationId ?? '' });
    const { data, setData, errors } = form;

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        const path = { receive: 'catalog.stock.receive', adjust: 'catalog.stock.adjust', minimum: 'catalog.stock.minimum' }[mode];

        form.transform((values) => (mode === 'minimum' ? { min_quantity: values.min_quantity, location_id: values.location_id } : values));

        if (mode === 'minimum') form.put(route(path, product.id), options);
        else form.post(route(path, product.id), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={submit} noValidate>
                <header className="border-b border-line px-5 py-4 sm:px-6">
                    <h2 className="text-lg font-bold text-ink">{copy.title}</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">
                        {product.name} · <strong className="text-ink">{place.name}</strong>
                        {mode !== 'minimum' && <> · hoy hay {units(place.quantity)}</>}
                    </p>
                </header>
                <div className="space-y-4 px-5 py-5 sm:px-6">
                    <div>
                        <InputLabel htmlFor="stock_value" value={copy.label} />
                        <TextInput id="stock_value" type="number" min={mode === 'receive' ? 1 : 0} step={1} inputMode="numeric" className="mt-1" placeholder={copy.placeholder} value={data[copy.field]} onChange={(event) => setData(copy.field, event.target.value)} invalid={Boolean(errors[copy.field])} autoFocus />
                        <p className="mt-1 text-xs text-ink-muted">{copy.help}</p>
                        <InputError message={errors[copy.field]} className="mt-1" />
                    </div>
                    {mode !== 'minimum' && (
                        <div>
                            <InputLabel htmlFor="stock_reason" value={copy.reasonLabel} />
                            <Textarea id="stock_reason" rows={2} className="mt-1" placeholder={copy.reasonPlaceholder} maxLength={255} value={data.reason} onChange={(event) => setData('reason', event.target.value)} invalid={Boolean(errors.reason)} />
                            <InputError message={errors.reason} className="mt-1" />
                        </div>
                    )}
                    <InputError message={errors.location_id} />
                </div>
                <div className="flex justify-end gap-3 border-t border-line px-5 py-4 sm:px-6">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {copy.submit}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

