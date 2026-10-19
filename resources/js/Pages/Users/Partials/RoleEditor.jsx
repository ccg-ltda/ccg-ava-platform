import { useForm } from '@inertiajs/react';
import { ChevronDown, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { groupPermissions, matchesSearch, missingRequirements, selectionDiff } from '@/lib/permissions';

const FILTERS = [
    { value: 'all', label: 'Todos' },
    { value: 'selected', label: 'Asignados' },
    { value: 'unselected', label: 'No asignados' },
];

/** One permission: a card with its label, what it allows and, when it needs another one to be reachable, which. Selecting it selects nothing else. */
function PermissionOption({ item, checked, disabled, onToggle, held }) {
    const missing = checked && item.requires && !held.has(item.requires);

    return (
        <label
            className={`flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm text-ink transition-colors duration-150 ${
                disabled ? 'cursor-default' : 'cursor-pointer hover:border-primary/40'
            } ${checked ? 'border-primary/50 bg-primary-soft/40' : 'border-field'} ${missing ? 'ring-1 ring-accent-amber/60' : ''}`}
        >
            <Checkbox className="mt-0.5 shrink-0" checked={checked} disabled={disabled} onChange={() => onToggle(item.name)} />
            <span className="min-w-0">
                <span className="font-medium">{item.label}</span>
                {item.platform && <span className="ml-2 align-middle text-[10px] font-semibold tracking-wide text-accent-violet uppercase">Solo Desarrollo_DEV</span>}
                {item.description && <span className="mt-0.5 block text-xs text-ink-muted">{item.description}</span>}
                <span className="mt-0.5 block font-mono text-[11px] text-ink-muted">{item.name}</span>
            </span>
        </label>
    );
}

/** A resource with its permissions, foldable, with explicit "select all / clear all" for the resource (nothing outside it changes). */
function GroupSection({ group, selected, held, readOnly, open, onToggleOpen, onToggle, onSetMany }) {
    const names = group.items.map((item) => item.name);
    const count = names.filter((name) => held.has(name)).length;

    return (
        <section className="rounded-xl border border-line bg-card">
            <div className="flex flex-wrap items-center gap-2 px-3 py-2.5 sm:px-4">
                <button
                    type="button"
                    onClick={onToggleOpen}
                    aria-expanded={open}
                    className="flex min-w-0 basis-full items-center gap-2 text-left focus-visible:outline-2 focus-visible:outline-primary sm:flex-1 sm:basis-0"
                >
                    <ChevronDown className={`size-4 shrink-0 text-ink-muted transition-transform duration-150 ${open ? '' : '-rotate-90'}`} aria-hidden="true" />
                    <span className="min-w-0">
                        <span className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-bold text-ink">{group.label}</span>
                            <Badge tone={count > 0 ? 'blue' : 'neutral'} className="normal-case tracking-normal">
                                {count}/{names.length}
                            </Badge>
                            {group.moduleName && <span className="text-[11px] text-ink-muted">Módulo: {group.moduleName}</span>}
                        </span>
                        {group.description && <span className="block truncate text-xs text-ink-muted">{group.description}</span>}
                    </span>
                </button>
                {!readOnly && (
                    <span className="flex shrink-0 gap-2 pl-6 sm:pl-0">
                        <button
                            type="button"
                            onClick={() => onSetMany(names, true)}
                            disabled={count === names.length}
                            className="rounded-md px-2 py-1 text-xs font-semibold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40"
                        >
                            Marcar los {names.length}
                        </button>
                        <button
                            type="button"
                            onClick={() => onSetMany(names, false)}
                            disabled={count === 0}
                            className="rounded-md px-2 py-1 text-xs font-semibold text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40"
                        >
                            Quitar todos
                        </button>
                    </span>
                )}
            </div>
            {open && (
                <div className="grid gap-2 border-t border-line p-3 sm:grid-cols-2 sm:p-4">
                    {group.items.map((item) => (
                        <PermissionOption key={item.name} item={item} checked={held.has(item.name)} held={held} disabled={readOnly} onToggle={onToggle} />
                    ))}
                </div>
            )}
        </section>
    );
}

/**
 * Role editor: a wide dialog with a bounded height. The header (name, search, counters) and the footer (warnings, actions) stay in
 * view while the permissions, grouped by resource, scroll inside. `mode`: `create` (name + permissions), `edit` (permissions of a role) or
 * `view` (read-only detail). The server validates and enforces everything; this only helps to choose.
 */
export default function RoleEditor({ show, mode, role, catalog, onClose }) {
    const initial = useMemo(() => (mode === 'create' ? [] : (role?.permissions ?? [])), [mode, role]);
    const form = useForm({ name: '', permissions: initial });
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState('all');
    const [folded, setFolded] = useState(() => new Set());

    const readOnly = mode === 'view';
    const held = new Set(form.data.permissions);
    const diff = selectionDiff(initial, form.data.permissions);
    const dirty = diff.added.length + diff.removed.length > 0 || (mode === 'create' && form.data.name !== '');
    const warnings = missingRequirements(catalog, form.data.permissions);
    const total = catalog.items.length;

    const groups = useMemo(
        () =>
            groupPermissions(catalog)
                .map((group) => ({
                    ...group,
                    items: group.items.filter(
                        (item) => matchesSearch(item, group, query) && (filter === 'all' || (filter === 'selected') === held.has(item.name)),
                    ),
                }))
                .filter((group) => group.items.length > 0),
        // `held` is derived from the form data on every render
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [catalog, query, filter, form.data.permissions],
    );

    const toggle = (name) => form.setData('permissions', held.has(name) ? form.data.permissions.filter((n) => n !== name) : [...form.data.permissions, name]);
    const setMany = (names, on) =>
        form.setData('permissions', on ? [...new Set([...form.data.permissions, ...names])] : form.data.permissions.filter((n) => !names.includes(n)));
    const toggleFolded = (id) =>
        setFolded((previous) => {
            const next = new Set(previous);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });

    const close = () => {
        form.reset();
        form.clearErrors();
        setQuery('');
        setFilter('all');
        onClose();
    };

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (mode === 'create') form.post(route('roles.store'), options);
        else form.put(route('roles.update', role.id), options);
    };

    const title = mode === 'create' ? 'Nuevo rol' : mode === 'edit' ? `Permisos de ${role?.name ?? ''}` : `Detalle de ${role?.name ?? ''}`;

    return (
        <Modal show={show} onClose={close} maxWidth="5xl" scrollable>
            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" aria-label={title}>
                <header className="shrink-0 space-y-3 border-b border-line p-4 sm:px-6">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className="text-lg font-bold text-ink">{title}</h2>
                            <p className="text-xs text-ink-muted">
                                {held.size} de {total} permisos {readOnly ? 'asignados' : 'seleccionados'}. Cada permiso es independiente: marcar uno no concede otro.
                            </p>
                        </div>
                        <button type="button" onClick={close} aria-label="Cerrar" className="rounded-md p-1.5 text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                            <X className="size-5" aria-hidden="true" />
                        </button>
                    </div>

                    {mode === 'create' && (
                        <div>
                            <InputLabel htmlFor="role_name" value="Nombre del rol" />
                            <TextInput
                                id="role_name"
                                className="mt-1 max-w-sm"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="ejemplo: coordinador"
                                invalid={Boolean(form.errors.name)}
                                required
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <SearchInput value={query} onChange={setQuery} placeholder="Buscar permisos" label="Buscar permisos" className="w-full sm:w-auto sm:min-w-0 sm:max-w-sm sm:flex-1" />
                        <div role="group" aria-label="Filtrar por asignación" className="flex gap-1">
                            {FILTERS.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={filter === option.value}
                                    onClick={() => setFilter(option.value)}
                                    className={`rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary ${
                                        filter === option.value ? 'border-primary bg-primary-soft text-primary' : 'border-field text-ink-muted hover:border-primary/40'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={() => setFolded(folded.size > 0 ? new Set() : new Set(groups.map((group) => group.id)))}
                            className="ml-auto text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary"
                        >
                            {folded.size > 0 ? 'Desplegar todo' : 'Plegar todo'}
                        </button>
                    </div>
                </header>

                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 sm:px-6" tabIndex={0} aria-label="Permisos por recurso">
                    {groups.length === 0 ? (
                        <p className="py-10 text-center text-sm text-ink-muted">Ningún permiso coincide con la búsqueda o el filtro.</p>
                    ) : (
                        groups.map((group) => (
                            <GroupSection
                                key={group.id}
                                group={group}
                                held={held}
                                readOnly={readOnly}
                                open={!folded.has(group.id) || query !== ''}
                                onToggleOpen={() => toggleFolded(group.id)}
                                onToggle={toggle}
                                onSetMany={setMany}
                            />
                        ))
                    )}
                    <InputError message={form.errors.permissions} />
                </div>

                <footer className="shrink-0 space-y-3 border-t border-line p-4 sm:px-6">
                    {warnings.length > 0 && !readOnly && (
                        <Alert tone="warning">
                            <p className="font-semibold">Permisos que no se podrán usar solos</p>
                            <p className="text-xs">
                                Cada operación se hace desde la página de su recurso. Falta «{[...new Set(warnings.map((w) => w.requiresLabel))].join('», «')}» para:{' '}
                                {warnings
                                    .slice(0, 4)
                                    .map((w) => w.label)
                                    .join(', ')}
                                {warnings.length > 4 ? ` y ${warnings.length - 4} más` : ''}. No se marca nada automáticamente.
                            </p>
                        </Alert>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p role="status" className="text-xs text-ink-muted">
                            {readOnly ? '' : dirty ? <strong className="text-ink">Cambios sin guardar: +{diff.added.length} · −{diff.removed.length}</strong> : 'Sin cambios.'}
                        </p>
                        <div className="flex gap-3">
                            <SecondaryButton type="button" onClick={close}>
                                {readOnly ? 'Cerrar' : 'Cancelar'}
                            </SecondaryButton>
                            {!readOnly && (
                                <PrimaryButton type="submit" disabled={form.processing || !dirty}>
                                    Guardar
                                </PrimaryButton>
                            )}
                        </div>
                    </div>
                </footer>
            </form>
        </Modal>
    );
}
