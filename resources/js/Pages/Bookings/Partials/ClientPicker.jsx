import axios from 'axios';
import { Link2, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import Checkbox from '@/Components/Checkbox';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

/**
 * Ties the person who books to their card in Clientes (the central record of people), or offers to save them as a new card. Optional: without it the dialog works exactly as before.
 * The search runs on the server over THIS Workspace's active cards (name, phone or e-mail, at least two characters); choosing one fills the three fields below and the server uses the card's
 * own data when it saves. The option to save a new person lives below the fields (`SaveInListing`); a duplicate is refused by the server naming the existing card.
 */
export default function ClientPicker({ capabilities, picked, onPick }) {
    const [term, setTerm] = useState('');
    const [found, setFound] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (picked || term.trim().length < 2) {
            setFound([]);

            return undefined;
        }

        const timer = setTimeout(() => {
            setBusy(true);
            axios
                .get(route('bookings.clients'), { params: { q: term.trim() } })
                .then((response) => setFound(response.data.clients))
                .catch(() => setFound([]))
                .finally(() => setBusy(false));
        }, 250);

        return () => clearTimeout(timer);
    }, [term, picked]);

    if (!capabilities?.enabled) return null;

    return (
        <div className="space-y-2 sm:col-span-2">
            <InputLabel htmlFor="appt_client_search" value="Cliente registrado (opcional)" />
            {picked ? (
                <p className="flex flex-wrap items-center gap-2 rounded-lg border border-primary/30 bg-primary-soft/50 px-3 py-2 text-sm text-ink">
                    <Link2 className="size-4 shrink-0 text-accent-blue" aria-hidden="true" />
                    <span className="min-w-0 flex-1 truncate">
                        Ficha de Clientes: <strong>{picked.name}</strong>
                    </span>
                    <button type="button" onClick={() => onPick(null)} className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-semibold text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                        <X className="size-3.5" aria-hidden="true" />
                        Quitar
                    </button>
                </p>
            ) : (
                <>
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
                        <TextInput id="appt_client_search" className="pl-9" value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Buscar por nombre, teléfono o correo" autoComplete="off" />
                    </div>
                    {term.trim().length >= 2 && (
                        <ul className="max-h-44 divide-y divide-line overflow-y-auto rounded-lg border border-line bg-card text-sm" aria-label="Clientes encontrados">
                            {busy && <li className="px-3 py-2 text-ink-muted">Buscando…</li>}
                            {!busy && found.length === 0 && <li className="px-3 py-2 text-ink-muted">Ningún cliente coincide. Escribe sus datos abajo{capabilities.canCreate ? ' y marca «Guardar en el listado»' : ''}.</li>}
                            {found.map((client) => (
                                <li key={client.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onPick(client);
                                            setTerm('');
                                        }}
                                        className="flex w-full flex-col px-3 py-2 text-left hover:bg-canvas focus-visible:bg-canvas focus-visible:outline-none"
                                    >
                                        <span className="font-semibold text-ink">{client.name}</span>
                                        <span className="text-xs text-ink-muted">{[client.phone, client.email].filter(Boolean).join(' · ') || 'Sin datos de contacto'}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </div>
    );
}

/** «Guardar en el listado»: below the fields, creates the person's card in Clientes (the one place that manages them) when they are not already there. Only for roles that may create cards. */
export function SaveInListing({ capabilities, picked, checked, onChange }) {
    if (!capabilities?.canCreate || picked) return null;

    return (
        <label className="flex items-start gap-2 text-sm text-ink sm:col-span-2">
            <Checkbox className="mt-0.5" checked={checked} onChange={(event) => onChange(event.target.checked)} />
            <span>
                Guardar en el listado
                <span className="block text-xs text-ink-muted">Crea su ficha en Clientes, sin consentimiento de mensajes. Si ya existe no se duplica.</span>
            </span>
        </label>
    );
}
