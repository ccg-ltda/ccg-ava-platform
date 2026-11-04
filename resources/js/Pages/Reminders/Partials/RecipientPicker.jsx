import axios from 'axios';
import { Search, UserPlus, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';

/** Why this customer cannot receive a reminder through the channel (null = can). The server checks again; this only warns early. */
export function ineligibleReason(customer, channel) {
    if (!customer.consent) return 'sin consentimiento';
    if (channel === 'whatsapp' && !customer.hasPhone) return 'sin teléfono';
    if (channel !== 'whatsapp' && !customer.hasEmail) return 'sin correo';

    return null;
}

const EMPTY = { first_name: '', last_name: '', phone: '', email: '', consent_granted: false, consent_channel: '' };

/** The same card as in Clientes, created without leaving the form. The server applies the duplicate rules and says which existing card collides. */
function QuickCreate({ onCreated, onCancel, consentChannels }) {
    const [values, setValues] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const set = (key, value) => setValues((current) => ({ ...current, [key]: value }));

    const save = () => {
        setBusy(true);
        setErrors({});
        axios
            .post(route('reminders.customers.store'), values)
            .then((response) => onCreated(response.data.customer))
            .catch((error) => setErrors(Object.fromEntries(Object.entries(error.response?.data?.errors ?? { form: ['No se pudo crear el cliente.'] }).map(([key, messages]) => [key, messages[0]]))))
            .finally(() => setBusy(false));
    };

    return (
        <fieldset className="space-y-3 rounded-xl border border-line bg-canvas/40 p-4">
            <legend className="px-1 text-sm font-semibold text-ink">Nuevo cliente</legend>
            <div className="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                <div className="min-w-0">
                    <InputLabel htmlFor="qc_first" value="Nombre" />
                    <TextInput id="qc_first" className="mt-1" value={values.first_name} onChange={(event) => set('first_name', event.target.value)} invalid={Boolean(errors.first_name)} autoComplete="off" />
                    <InputError message={errors.first_name} className="mt-1" />
                </div>
                <div className="min-w-0">
                    <InputLabel htmlFor="qc_last" value="Apellido" />
                    <TextInput id="qc_last" className="mt-1" value={values.last_name} onChange={(event) => set('last_name', event.target.value)} autoComplete="off" />
                </div>
                <div className="min-w-0">
                    <InputLabel htmlFor="qc_phone" value="Teléfono" />
                    <TextInput id="qc_phone" className="mt-1" value={values.phone} onChange={(event) => set('phone', event.target.value)} invalid={Boolean(errors.phone)} placeholder="+57 300 123 4567" autoComplete="off" />
                    <InputError message={errors.phone} className="mt-1" />
                </div>
                <div className="min-w-0">
                    <InputLabel htmlFor="qc_email" value="Correo" />
                    <TextInput id="qc_email" type="email" className="mt-1" value={values.email} onChange={(event) => set('email', event.target.value)} invalid={Boolean(errors.email)} autoComplete="off" />
                    <InputError message={errors.email} className="mt-1" />
                </div>
            </div>
            <label className="flex items-start gap-2 text-sm text-ink">
                <Checkbox className="mt-0.5" checked={values.consent_granted} onChange={(event) => set('consent_granted', event.target.checked)} />
                <span>
                    Esta persona aceptó recibir mensajes comerciales
                    <span className="block text-xs text-ink-muted">Sin esto la ficha se crea, pero no podrá recibir el recordatorio.</span>
                </span>
            </label>
            {values.consent_granted && (
                <div className="min-w-0">
                    <InputLabel htmlFor="qc_channel" value="Canal por el que autorizó" />
                    <Select id="qc_channel" className="mt-1" value={values.consent_channel} onChange={(value) => set('consent_channel', value)} options={Object.entries(consentChannels).map(([value, label]) => ({ value, label }))} placeholder="Elige un canal" invalid={Boolean(errors.consent_channel)} />
                    <InputError message={errors.consent_channel} className="mt-1" />
                    <p className="mt-1 text-xs text-ink-muted">La fecha y la hora las registra Ava al guardar.</p>
                </div>
            )}
            <InputError message={errors.form ?? errors.contact ?? errors.customer} />
            <div className="flex flex-wrap justify-end gap-2">
                <SecondaryButton type="button" onClick={onCancel}>Cancelar</SecondaryButton>
                <PrimaryButton type="button" disabled={busy} onClick={save}>Crear y añadir</PrimaryButton>
            </div>
        </fieldset>
    );
}

/**
 * «Cliente(s)»: ONE field to choose who receives the reminder. It searches the central Clientes table of this Workspace by name, last name, phone or e-mail; every choice becomes a chip
 * (each one gets their own message and status), and a person who is not there yet can be created in place. A chip shows early why someone could not receive it through the chosen
 * channel; the server decides in the end and reports it per person.
 */
export default function RecipientPicker({ selected, onChange, channel, canCreate, error, limit, consentChannels = {} }) {
    const listId = useId();
    const [term, setTerm] = useState('');
    const [found, setFound] = useState([]);
    const [busy, setBusy] = useState(false);
    const [creating, setCreating] = useState(false);

    useEffect(() => {
        if (term.trim().length < 2) {
            setFound([]);

            return undefined;
        }

        const timer = setTimeout(() => {
            setBusy(true);
            axios
                .get(route('reminders.customers'), { params: { q: term.trim() } })
                .then((response) => setFound(response.data.customers))
                .catch(() => setFound([]))
                .finally(() => setBusy(false));
        }, 250);

        return () => clearTimeout(timer);
    }, [term]);

    const chosen = new Set(selected.map((customer) => customer.id));
    const add = (customer) => {
        if (chosen.has(customer.id) || selected.length >= limit) return;
        onChange([...selected, customer]);
        setTerm('');
        setFound([]);
    };
    const remove = (id) => onChange(selected.filter((customer) => customer.id !== id));
    const options = found.filter((customer) => !chosen.has(customer.id));

    return (
        <div className="space-y-2 sm:col-span-2">
            <InputLabel htmlFor="rem_customer_search" value="Cliente(s)" />
            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label="Clientes elegidos">
                    {selected.map((customer) => {
                        const reason = ineligibleReason(customer, channel);

                        return (
                            <li key={customer.id} className={`inline-flex max-w-full items-center gap-1.5 rounded-full border py-1 pr-1 pl-3 text-sm ${reason ? 'border-warning/60 bg-warning/10' : 'border-primary/30 bg-primary-soft/50'}`}>
                                <span className="min-w-0 truncate font-semibold text-ink">{customer.name}</span>
                                {reason && <span className="shrink-0 text-xs text-warning">· {reason}</span>}
                                <button type="button" onClick={() => remove(customer.id)} aria-label={`Quitar a ${customer.name}`} className="rounded-full p-1 text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                                    <X className="size-3.5" aria-hidden="true" />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {selected.length >= limit ? (
                <p className="text-xs text-ink-muted">Máximo {limit} clientes por envío.</p>
            ) : (
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
                    <TextInput
                        id="rem_customer_search"
                        role="combobox"
                        aria-expanded={term.trim().length >= 2}
                        aria-controls={listId}
                        className="pl-9"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Buscar por nombre, apellido, teléfono o correo"
                        autoComplete="off"
                    />
                </div>
            )}
            {term.trim().length >= 2 && (
                <ul id={listId} role="listbox" className="max-h-52 divide-y divide-line overflow-y-auto rounded-lg border border-line bg-card text-sm" aria-label="Clientes encontrados">
                    {busy && <li className="px-3 py-2 text-ink-muted">Buscando…</li>}
                    {!busy && options.length === 0 && <li className="px-3 py-2 text-ink-muted">Ningún cliente coincide.</li>}
                    {options.map((customer) => {
                        const reason = ineligibleReason(customer, channel);

                        return (
                            <li key={customer.id} role="option" aria-selected="false">
                                <button type="button" onClick={() => add(customer)} className="flex w-full flex-col px-3 py-2 text-left hover:bg-canvas focus-visible:bg-canvas focus-visible:outline-none">
                                    <span className="font-semibold text-ink">{customer.name}{reason && <span className="ml-2 text-xs font-normal text-warning">({reason})</span>}</span>
                                    <span className="text-xs text-ink-muted">{[customer.phone, customer.email].filter(Boolean).join(' · ') || 'Sin datos de contacto'}</span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <InputError message={error} />
            {canCreate && !creating && (
                <button type="button" onClick={() => setCreating(true)} className="inline-flex items-center gap-1.5 rounded text-sm font-semibold text-accent-blue hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                    <UserPlus className="size-4" aria-hidden="true" />
                    Crear nuevo cliente
                </button>
            )}
            {creating && (
                <QuickCreate
                    consentChannels={consentChannels}
                    onCancel={() => setCreating(false)}
                    onCreated={(customer) => {
                        add(customer);
                        setCreating(false);
                    }}
                />
            )}
        </div>
    );
}
