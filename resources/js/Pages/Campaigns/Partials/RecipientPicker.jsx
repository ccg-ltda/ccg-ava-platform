import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Pagination from '@/Components/Pagination';
import SearchInput from '@/Components/SearchInput';
import usePagedList from '@/Hooks/usePagedList';

/**
 * Hand-picking of recipients: the customers of the Workspace (searched on the server) with a checkbox each. A customer the campaign
 * would NOT reach (no consent, opted out, no contact datum...) shows the reason and cannot be picked. The picked ids travel with the draft.
 */
export default function RecipientPicker({ campaignId, picker, selected, onChange, exclusions, perPageOptions, max }) {
    const [search, setSearch] = useState(picker.search ?? '');
    const firstRender = useRef(true);
    const list = usePagedList({
        section: 'picker',
        only: ['picker'],
        meta: picker.meta,
        perPageOptions,
        routeName: 'campaigns.show',
        routeParams: campaignId,
        extra: { pick: picker.search || undefined },
    });

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return undefined;
        }

        const timer = setTimeout(() => list.visit({ pick: search || undefined }, { restart: true }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const toggle = (id) => onChange(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id]);
    const pickable = picker.data.filter((customer) => !customer.reason);
    const allOnPage = pickable.length > 0 && pickable.every((customer) => selected.includes(customer.id));
    const togglePage = () => onChange(allOnPage ? selected.filter((id) => !pickable.some((customer) => customer.id === id)) : [...new Set([...selected, ...pickable.map((customer) => customer.id)])]);

    return (
        <div className="space-y-3">
            <SearchInput value={search} onChange={setSearch} placeholder="Buscar clientes para elegir" label="Buscar clientes" />
            {selected.length >= max && <Alert tone="warning">Alcanzaste el máximo de {max} destinatarios elegidos a mano.</Alert>}
            <div className="overflow-hidden rounded-lg border border-line">
                <label className="flex items-center gap-3 border-b border-line bg-canvas px-4 py-2.5 text-sm font-semibold text-ink">
                    <input type="checkbox" className="size-4 accent-primary" checked={allOnPage} onChange={togglePage} disabled={pickable.length === 0} />
                    Elegir los de esta página ({selected.length} elegidos en total)
                </label>
                <ul className={`divide-y divide-line transition-opacity ${list.loading ? 'opacity-60' : ''}`}>
                    {picker.data.length === 0 && <li className="px-4 py-6 text-center text-sm text-ink-muted">Ningún cliente coincide.</li>}
                    {picker.data.map((customer) => (
                        <li key={customer.id}>
                            <label className={`flex items-center gap-3 px-4 py-2.5 text-sm ${customer.reason ? 'opacity-60' : 'cursor-pointer hover:bg-canvas'}`}>
                                <input type="checkbox" className="size-4 accent-primary" checked={selected.includes(customer.id)} disabled={Boolean(customer.reason) || (!selected.includes(customer.id) && selected.length >= max)} onChange={() => toggle(customer.id)} />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium text-ink">{customer.name}</span>
                                    <span className="block truncate text-xs text-ink-muted">{[customer.phone, customer.email].filter(Boolean).join(' · ') || 'Sin datos de contacto'}</span>
                                </span>
                                {customer.reason && <span className="shrink-0 text-xs text-accent-amber">{exclusions[customer.reason]}</span>}
                            </label>
                        </li>
                    ))}
                </ul>
                <Pagination {...list.paginationProps} noun="clientes" />
            </div>
        </div>
    );
}
