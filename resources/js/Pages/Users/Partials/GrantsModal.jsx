import { Check, X } from 'lucide-react';
import RoleLabel from '@/Components/RoleLabel';
import { useMemo, useState } from 'react';
import Badge from '@/Components/Badge';
import Modal from '@/Components/Modal';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import { groupPermissions, matchesSearch } from '@/lib/permissions';

/** One granted permission: its label and what it allows, as a compact read-only card. */
function GrantCard({ item }) {
    return (
        <li className="flex items-start gap-2.5 rounded-lg border border-primary/30 bg-primary-soft/40 px-3 py-2">
            <Check className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
            <span className="min-w-0 text-sm text-ink">
                <span className="font-medium">{item.label}</span>
                {item.platform && <span className="ml-2 align-middle text-[10px] font-semibold tracking-wide text-accent-violet uppercase">Solo Desarrollo_DEV</span>}
                {item.description && <span className="mt-0.5 block text-xs text-ink-muted">{item.description}</span>}
            </span>
        </li>
    );
}

/**
 * What a person can do in the Workspace: the permissions their role grants, grouped by resource (read-only). A wide dialog with a bounded
 * height: the header (who, role, counters, search) and the footer stay in view while the groups scroll inside, so the page behind never moves.
 */
export default function GrantsModal({ user, catalog, onClose }) {
    const [query, setQuery] = useState('');
    const role = user?.roles?.[0];

    const groups = useMemo(() => (user ? groupPermissions(catalog, user.grants) : []), [user, catalog]);
    const totals = useMemo(() => Object.fromEntries(groupPermissions(catalog).map((group) => [group.id, group.items.length])), [catalog]);
    const granted = groups.reduce((sum, group) => sum + group.items.length, 0);

    const visible = groups
        .map((group) => ({ ...group, items: group.items.filter((item) => matchesSearch(item, group, query)) }))
        .filter((group) => group.items.length > 0);

    const close = () => {
        setQuery('');
        onClose();
    };

    return (
        <Modal show={Boolean(user)} onClose={close} maxWidth="4xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="shrink-0 space-y-3 border-b border-line p-4 sm:px-6">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className="truncate text-lg font-bold text-ink">Permisos de {user?.name}</h2>
                            <p className="mt-1 text-sm text-ink-muted">
                                Los da su rol <RoleLabel role={role} /> en este Workspace. Se cambian en la pestaña Roles.
                            </p>
                        </div>
                        <button type="button" onClick={close} aria-label="Cerrar" className="shrink-0 rounded-md p-1.5 text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                            <X className="size-5" aria-hidden="true" />
                        </button>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <SearchInput value={query} onChange={setQuery} placeholder="Buscar permisos" label="Buscar permisos" className="w-full sm:max-w-sm sm:flex-1" />
                        <p role="status" className="text-xs text-ink-muted">
                            <strong className="text-ink">{granted}</strong> permisos en <strong className="text-ink">{groups.length}</strong> áreas
                        </p>
                    </div>
                </header>

                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4 sm:px-6" tabIndex={0} aria-label="Permisos por recurso">
                    {groups.length === 0 && <p className="py-10 text-center text-sm text-ink-muted">Este rol no tiene permisos.</p>}
                    {groups.length > 0 && visible.length === 0 && <p className="py-10 text-center text-sm text-ink-muted">Ningún permiso coincide con la búsqueda.</p>}
                    {visible.map((group) => (
                        <section key={group.id} className="rounded-xl border border-line bg-card">
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-3 py-2.5 sm:px-4">
                                <h3 className="text-sm font-bold text-ink">{group.label}</h3>
                                <Badge tone="blue" className="normal-case tracking-normal">
                                    {groups.find((g) => g.id === group.id).items.length}/{totals[group.id]}
                                </Badge>
                                {group.description && <p className="basis-full text-xs text-ink-muted">{group.description}</p>}
                            </div>
                            <ul className="grid gap-2 p-3 sm:grid-cols-2 sm:p-4">
                                {group.items.map((item) => (
                                    <GrantCard key={item.name} item={item} />
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>

                <footer className="flex shrink-0 justify-end border-t border-line p-4 sm:px-6">
                    <SecondaryButton type="button" onClick={close}>
                        Cerrar
                    </SecondaryButton>
                </footer>
            </div>
        </Modal>
    );
}
