import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';

const optionsOf = (map) => Object.entries(map).map(([value, label]) => ({ value, label }));

/** Create / edit form of a customer. Required: first name and one way to reach or recognise them; the rest is optional. */
export default function CustomerFormModal({ customer, options, onClose }) {
    const editing = Boolean(customer?.id);
    const form = useForm({
        first_name: customer?.firstName ?? '',
        last_name: customer?.lastName ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        source: customer?.source ?? 'manual',
        notes: customer?.notes ?? '',
        marketing_consent: customer?.consent ?? 'unknown',
        consent_note: customer?.consentNote ?? '',
        preferred_channel: customer?.preferredChannel ?? '',
        tags: (customer?.tags ?? []).join(', '),
        identities: customer?.identities ?? [],
    });
    const { data, setData, errors } = form;

    const setIdentity = (index, field, value) => setData('identities', data.identities.map((identity, i) => (i === index ? { ...identity, [field]: value } : identity)));

    const submit = (event) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            preferred_channel: values.preferred_channel || null,
            tags: values.tags.split(',').map((tag) => tag.trim()).filter(Boolean),
        }));
        const request = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('customers.update', customer.id), request);
        else form.post(route('customers.store'), request);
    };

    const field = (name, label, props = {}) => (
        <div>
            <InputLabel htmlFor={`customer_${name}`} value={label} />
            <TextInput id={`customer_${name}`} className="mt-1" value={data[name]} onChange={(e) => setData(name, e.target.value)} invalid={Boolean(errors[name])} {...props} />
            <InputError message={errors[name]} className="mt-1" />
        </div>
    );

    return (
        <Modal show onClose={onClose} maxWidth="lg" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar cliente' : 'Nuevo cliente'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-8">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('first_name', 'Nombre', { maxLength: 100 })}
                        {field('last_name', 'Apellidos (opcional)', { maxLength: 100 })}
                        {field('phone', 'Teléfono (opcional)', { maxLength: 32, inputMode: 'tel' })}
                        {field('email', 'Correo (opcional)', { type: 'email', maxLength: 160 })}
                    </div>
                    <p className="text-xs text-ink-muted">
                        Indica al menos un teléfono, un correo o un identificador de canal. Si ya existe un cliente con el mismo teléfono, correo o identificador, Ava no crea otro ni los combina por su cuenta.
                    </p>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium text-ink">Canales e identificadores (opcional)</legend>
                        {data.identities.map((identity, index) => (
                            <div key={index} className="flex items-start gap-2">
                                <Select className="w-36 shrink-0" aria-label="Canal" value={identity.channel} onChange={(value) => setIdentity(index, 'channel', value)} options={optionsOf(options.channels)} />
                                <div className="min-w-0 flex-1">
                                    <TextInput
                                        aria-label="Identificador en el canal"
                                        value={identity.external_id}
                                        maxLength={64}
                                        placeholder="Número o id del contacto"
                                        onChange={(e) => setIdentity(index, 'external_id', e.target.value)}
                                        invalid={Boolean(errors[`identities.${index}.external_id`])}
                                    />
                                    <InputError message={errors[`identities.${index}.external_id`] ?? errors[`identities.${index}.channel`]} className="mt-1" />
                                </div>
                                <button
                                    type="button"
                                    aria-label="Quitar identificador"
                                    onClick={() => setData('identities', data.identities.filter((_, i) => i !== index))}
                                    className="grid size-10 shrink-0 place-items-center rounded-lg text-danger hover:bg-danger/10"
                                >
                                    <Trash2 className="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        ))}
                        <SecondaryButton type="button" className="gap-2" onClick={() => setData('identities', [...data.identities, { channel: Object.keys(options.channels)[0], external_id: '' }])}>
                            <Plus className="size-4" aria-hidden="true" />
                            Añadir canal
                        </SecondaryButton>
                        <InputError message={errors.identities} />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="customer_source" value="Origen del contacto" />
                            <Select id="customer_source" className="mt-1" value={data.source} onChange={(value) => setData('source', value)} options={optionsOf(options.sources)} />
                            <InputError message={errors.source} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="customer_preferred" value="Canal preferido (opcional)" />
                            <Select
                                id="customer_preferred"
                                className="mt-1"
                                value={data.preferred_channel}
                                onChange={(value) => setData('preferred_channel', value)}
                                options={[{ value: '', label: 'Sin preferencia' }, ...optionsOf(options.channels)]}
                            />
                            <InputError message={errors.preferred_channel} className="mt-1" />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="customer_consent" value="Consentimiento para mensajes comerciales" />
                            <Select id="customer_consent" className="mt-1" value={data.marketing_consent} onChange={(value) => setData('marketing_consent', value)} options={optionsOf(options.consents)} />
                            <InputError message={errors.marketing_consent} className="mt-1" />
                        </div>
                        {field('consent_note', data.marketing_consent === 'granted' ? 'Cómo se obtuvo (obligatorio)' : 'Constancia (opcional)', { maxLength: 255 })}
                    </div>

                    {field('tags', 'Etiquetas (separadas por comas)', { placeholder: 'vip, mayorista' })}
                    <div>
                        <InputLabel htmlFor="customer_notes" value="Notas (opcional)" />
                        <Textarea id="customer_notes" className="mt-1" value={data.notes} maxLength={2000} onChange={(e) => setData('notes', e.target.value)} />
                        <InputError message={errors.notes} className="mt-1" />
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Guardar cambios' : 'Crear cliente'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
