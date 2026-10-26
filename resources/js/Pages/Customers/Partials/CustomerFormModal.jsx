import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import EntityPicker from '@/Components/EntityPicker';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { MAX_TAG_LENGTH, cleanTag, normalizeTag, withTag } from '@/lib/tags';

const optionsOf = (map) => Object.entries(map).map(([value, label]) => ({ value, label }));

/**
 * One field = label row, control row and message row. Inside a form grid the three rows are shared with the neighbour (subgrid): a label that
 * wraps to two lines lowers BOTH inputs equally, and an error under one field only grows the message row, never the neighbour's control.
 */
function Field({ id, label, error, hint, className = '', children }) {
    return (
        <div className={`row-span-3 grid min-w-0 grid-rows-subgrid gap-y-1 ${className}`}>
            <InputLabel htmlFor={id} value={label} className="self-end leading-snug" />
            <div className="min-w-0">{children}</div>
            <div className="min-w-0 space-y-0.5">
                {hint && <p className="text-xs text-ink-muted">{hint}</p>}
                <InputError message={error} />
            </div>
        </div>
    );
}

const GRID = 'grid gap-x-5 gap-y-4 sm:grid-cols-2';

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
        tags: customer?.tags ?? [],
        identities: customer?.identities ?? [],
    });
    const { data, setData, errors } = form;
    const [newTag, setNewTag] = useState('');
    // The tags of the Workspace catalog plus the ones this customer already has (a deactivated tag stays visible and removable).
    const tagOptions = [...new Set([...(options.tagCatalog ?? []), ...data.tags])].map((name) => ({ value: name, label: name }));
    const addTag = () => {
        setData('tags', withTag(data.tags, newTag));
        setNewTag('');
    };

    const setIdentity = (index, field, value) => setData('identities', data.identities.map((identity, i) => (i === index ? { ...identity, [field]: value } : identity)));

    const submit = (event) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            preferred_channel: values.preferred_channel || null,
        }));
        const request = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('customers.update', customer.id), request);
        else form.post(route('customers.store'), request);
    };

    const field = (name, label, props = {}) => (
        <Field id={`customer_${name}`} label={label} error={errors[name]} className={props.wide ? 'sm:col-span-2' : ''}>
            <TextInput id={`customer_${name}`} value={data[name]} onChange={(e) => setData(name, e.target.value)} invalid={Boolean(errors[name])} {...Object.fromEntries(Object.entries(props).filter(([key]) => key !== 'wide'))} />
        </Field>
    );

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar cliente' : 'Nuevo cliente'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-8">
                    <div className={GRID}>
                        {field('first_name', 'Nombre', { maxLength: 100 })}
                        {field('last_name', 'Apellidos (opcional)', { maxLength: 100 })}
                        {field('phone', 'Teléfono internacional (opcional)', { maxLength: 32, inputMode: 'tel', placeholder: '+573001112233' })}
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

                    <div className={GRID}>
                        <Field id="customer_source" label="Origen del contacto" error={errors.source}>
                            <Select id="customer_source" value={data.source} onChange={(value) => setData('source', value)} options={optionsOf(options.sources)} />
                        </Field>
                        <Field id="customer_preferred" label="Canal preferido (opcional)" error={errors.preferred_channel}>
                            <Select
                                id="customer_preferred"
                                value={data.preferred_channel}
                                onChange={(value) => setData('preferred_channel', value)}
                                options={[{ value: '', label: 'Sin preferencia' }, ...optionsOf(options.preferences)]}
                            />
                            {customer?.legacyPreferredChannel && <p className="mt-1 text-xs text-ink-muted">Valor anterior: {customer.legacyPreferredChannel}. Ya no es una preferencia de contacto; al guardar queda «Sin preferencia».</p>}
                        </Field>
                        <Field id="customer_consent" label="Consentimiento para mensajes comerciales" error={errors.marketing_consent}>
                            <Select id="customer_consent" value={data.marketing_consent} onChange={(value) => setData('marketing_consent', value)} options={optionsOf(options.consents)} />
                        </Field>
                        {field('consent_note', data.marketing_consent === 'granted' ? 'Cómo se obtuvo (obligatorio)' : 'Constancia (opcional)', { maxLength: 255 })}
                        <Field id="customer_tags" label="Etiquetas" error={errors.tags} className="sm:col-span-2" hint="Elige del catálogo del Workspace o crea una nueva: si ya existe con otra escritura (mayúsculas, espacios) se usa la existente.">
                            <EntityPicker id="customer_tags" value={data.tags} onChange={(tags) => setData('tags', tags)} options={tagOptions} placeholder="Buscar etiqueta" noneLabel="Sin etiquetas" invalid={Boolean(errors.tags)} />
                            <div className="mt-2 flex gap-2">
                                <TextInput aria-label="Nueva etiqueta" value={newTag} maxLength={MAX_TAG_LENGTH} placeholder="Crear etiqueta nueva" onChange={(e) => setNewTag(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } }} />
                                <SecondaryButton type="button" onClick={addTag} disabled={cleanTag(newTag) === '' || data.tags.some((tag) => normalizeTag(tag) === normalizeTag(newTag))}>
                                    Añadir
                                </SecondaryButton>
                            </div>
                        </Field>
                        <Field id="customer_notes" label="Notas (opcional)" error={errors.notes} className="sm:col-span-2">
                            <Textarea id="customer_notes" rows={4} value={data.notes} maxLength={2000} onChange={(e) => setData('notes', e.target.value)} invalid={Boolean(errors.notes)} />
                        </Field>
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
