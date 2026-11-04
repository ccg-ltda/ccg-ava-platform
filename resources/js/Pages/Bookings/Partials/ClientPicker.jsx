import axios from 'axios';
import { Link2, Loader2, UserPlus, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';

const MIN_CHARS = 2;

/**
 * The «Nombre» field of the appointment form, as a combobox over the Workspace's central Clientes. Typing filters on the SERVER (name, last name, phone or e-mail; the first ten matches, so it
 * is the same with five customers or five thousand) in a list with a limited height and its own scroll. Choosing a customer fills their name, phone and e-mail (the two that are empty stay
 * editable); typing a name that is not there offers «Crear cliente» or simply keeps it as a one-off booking. Without Clientes (plan or role) it is a plain text field, exactly as before.
 * Editing the name of a chosen customer unlinks it: the link is explicit, never inferred from what is typed.
 */
export function ClientNameField({ capabilities, name, onName, picked, onPick, onCreate, error }) {
    const listId = useId();
    const box = useRef(null);
    const [open, setOpen] = useState(false);
    const [found, setFound] = useState([]);
    const [busy, setBusy] = useState(false);
    const [active, setActive] = useState(-1);
    const enabled = Boolean(capabilities?.enabled);
    const term = name.trim();
    const searching = enabled && !picked && term.length >= MIN_CHARS;

    useEffect(() => {
        if (!searching || !open) {
            setFound([]);

            return undefined;
        }

        let live = true;
        const timer = setTimeout(() => {
            setBusy(true);
            axios
                .get(route('bookings.clients'), { params: { q: term } })
                .then((response) => live && setFound(response.data.clients))
                .catch(() => live && setFound([]))
                .finally(() => live && setBusy(false));
        }, 200);

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [term, searching, open]);

    useEffect(() => {
        const close = (event) => {
            if (box.current && !box.current.contains(event.target)) setOpen(false);
        };
        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const exact = found.some((client) => client.name.trim().toLowerCase() === term.toLowerCase());
    const rows = [...found.map((client) => ({ type: 'client', client })), ...(capabilities?.canCreate && !exact && term.length >= MIN_CHARS && !busy ? [{ type: 'create' }] : [])];

    const choose = (row) => {
        if (row.type === 'client') onPick(row.client);
        else onCreate();
        setOpen(false);
        setActive(-1);
    };

    const onKey = (event) => {
        if (!searching || !open) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(rows.length - 1, index + 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(0, index - 1));
        } else if (event.key === 'Enter' && active >= 0 && rows[active]) {
            event.preventDefault();
            choose(rows[active]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div ref={box} className="relative">
            <InputLabel htmlFor="appt_name" value="Nombre" />
            <TextInput
                id="appt_name"
                className="mt-1"
                value={name}
                maxLength={120}
                autoComplete="off"
                role={enabled ? 'combobox' : undefined}
                aria-expanded={enabled ? searching && open : undefined}
                aria-controls={enabled ? listId : undefined}
                aria-autocomplete={enabled ? 'list' : undefined}
                invalid={Boolean(error)}
                placeholder={enabled ? 'Escribe para buscar un cliente o un nombre nuevo' : undefined}
                onChange={(event) => {
                    onName(event.target.value);
                    setOpen(true);
                    setActive(-1);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={onKey}
            />
            <InputError message={error} className="mt-1" />
            {searching && open && (
                <ul id={listId} role="listbox" aria-label="Clientes encontrados" className="absolute z-30 mt-1 max-h-60 w-full divide-y divide-line overflow-y-auto rounded-lg border border-line bg-card text-sm shadow-card">
                    {busy && (
                        <li className="flex items-center gap-2 px-3 py-2 text-ink-muted">
                            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                            Buscando…
                        </li>
                    )}
                    {!busy && found.length === 0 && <li className="px-3 py-2 text-ink-muted">Ningún cliente coincide con «{term}».</li>}
                    {rows.map((row, index) =>
                        row.type === 'client' ? (
                            <li key={row.client.id} role="option" aria-selected={active === index}>
                                <button type="button" onClick={() => choose(row)} onMouseEnter={() => setActive(index)} className={`flex w-full flex-col px-3 py-2 text-left focus-visible:outline-none ${active === index ? 'bg-canvas' : 'hover:bg-canvas'}`}>
                                    <span className="font-semibold text-ink">{row.client.name}</span>
                                    <span className="text-xs text-ink-muted">{[row.client.phone, row.client.email].filter(Boolean).join(' · ') || 'Sin datos de contacto'}</span>
                                </button>
                            </li>
                        ) : (
                            <li key="create" role="option" aria-selected={active === index}>
                                <button type="button" onClick={() => choose(row)} onMouseEnter={() => setActive(index)} className={`flex w-full items-center gap-2 px-3 py-2 text-left font-semibold text-accent-blue focus-visible:outline-none ${active === index ? 'bg-canvas' : 'hover:bg-canvas'}`}>
                                    <UserPlus className="size-4 shrink-0" aria-hidden="true" />
                                    Crear cliente «{term}»
                                </button>
                            </li>
                        ),
                    )}
                    {!busy && found.length >= 10 && <li className="px-3 py-1.5 text-xs text-ink-muted">Se muestran los 10 primeros: escribe más para afinar.</li>}
                </ul>
            )}
        </div>
    );
}

/** Whether this booking is tied to a customer of the central list, said below the fields (and how to unlink it). Nothing when the role/plan has no Clientes. */
export function RelationStatus({ capabilities, picked, saveAsClient, onUnlink }) {
    if (!capabilities?.enabled) return null;

    if (picked) {
        return (
            <p className="flex flex-wrap items-center gap-2 rounded-lg border border-primary/30 bg-primary-soft/50 px-3 py-2 text-sm text-ink sm:col-span-2" role="status">
                <Link2 className="size-4 shrink-0 text-accent-blue" aria-hidden="true" />
                <span className="min-w-0 flex-1">
                    Asociada al cliente <strong>{picked.name}</strong> del listado
                    {picked.consentLabel ? <span className="text-ink-muted"> · Consentimiento: {picked.consentLabel}</span> : null}
                </span>
                <button type="button" onClick={onUnlink} className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-semibold text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                    <X className="size-3.5" aria-hidden="true" />
                    Quitar
                </button>
            </p>
        );
    }

    return (
        <p className="text-xs text-ink-muted sm:col-span-2" role="status">
            {saveAsClient ? 'Se creará un cliente nuevo en el listado y la reserva quedará asociada a él.' : 'Sin cliente del listado: la reserva guarda solo los datos de la persona para esta cita.'}
        </p>
    );
}

/**
 * «Guardar en el listado», below the fields: creates the person's card in Clientes (the one place that manages them) when they are not already there. If the person authorized marketing messages
 * it is recorded EXPLICITLY, with the channel they used; the date and time are stamped by the server. Booking, or giving a phone number, is never consent. Only for roles that may create cards.
 */
export function SaveInListing({ capabilities, picked, checked, onChange, consent, onConsent, errors = {} }) {
    const id = useId();

    if (!capabilities?.canCreate || picked) return null;

    const channels = Object.entries(capabilities.consentChannels ?? {}).map(([value, label]) => ({ value, label }));

    return (
        <div className="space-y-3 sm:col-span-2">
            <label className="flex items-start gap-2 text-sm text-ink">
                <Checkbox className="mt-0.5" checked={checked} onChange={(event) => onChange(event.target.checked)} />
                <span>
                    Guardar en el listado
                    <span className="block text-xs text-ink-muted">Crea su ficha en Clientes. Si ya existe no se duplica.</span>
                </span>
            </label>
            {checked && (
                <div className="ml-6 space-y-2">
                    <label className="flex items-start gap-2 text-sm text-ink">
                        <Checkbox className="mt-0.5" checked={consent.granted} onChange={(event) => onConsent({ ...consent, granted: event.target.checked, channel: event.target.checked ? consent.channel : '' })} />
                        <span>
                            Autorizó recibir mensajes comerciales
                            <span className="block text-xs text-ink-muted">Déjalo sin marcar si no lo dijo: reservar no es una autorización.</span>
                        </span>
                    </label>
                    {consent.granted && (
                        <div className="max-w-xs">
                            <InputLabel htmlFor={`${id}_channel`} value="Canal por el que autorizó" />
                            <Select id={`${id}_channel`} className="mt-1" value={consent.channel} onChange={(value) => onConsent({ ...consent, channel: value })} options={channels} placeholder="Elige un canal" invalid={Boolean(errors.consent_channel)} />
                            <InputError message={errors.consent_channel} className="mt-1" />
                            <p className="mt-1 text-xs text-ink-muted">La fecha y la hora las registra Ava al guardar.</p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
