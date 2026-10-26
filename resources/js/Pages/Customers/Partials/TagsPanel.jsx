import { Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Tag, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';

const MAX_LENGTH = 40;

const customersLabel = (count) => (count === 0 ? 'Sin clientes' : `${count.toLocaleString('es')} cliente${count === 1 ? '' : 's'}`);

/** Creates a tag. The server normalizes the name (spaces, Unicode, lower case) and refuses an empty or repeated one; the message shows under the field. */
function NewTag() {
    const form = useForm({ name: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('customers.tags.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div>
                <InputLabel htmlFor="new_tag_name" value="Nueva etiqueta" />
                <TextInput id="new_tag_name" className="mt-1" value={form.data.name} maxLength={MAX_LENGTH} placeholder="Ej. mayorista" onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <PrimaryButton type="submit" disabled={form.processing} className="gap-2 sm:mt-[1.375rem] sm:self-start">
                <Plus className="size-4" aria-hidden="true" />
                Crear etiqueta
            </PrimaryButton>
        </form>
    );
}

/** Renames one tag in place (the customers that have it keep it: they are linked by id). */
function RenameTag({ tag, onDone }) {
    const form = useForm({ name: tag.name });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('customers.tags.update', tag.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-wrap items-start gap-2">
            <div className="min-w-0 flex-1 basis-48">
                <TextInput aria-label={`Nombre de la etiqueta ${tag.name}`} autoFocus value={form.data.name} maxLength={MAX_LENGTH} onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <PrimaryButton type="submit" disabled={form.processing}>
                Guardar
            </PrimaryButton>
            <SecondaryButton type="button" onClick={onDone}>
                Cancelar
            </SecondaryButton>
        </form>
    );
}

/**
 * Tab «Etiquetas» of Clientes: the tags of the Workspace (active or not) with the customers that have each one. Creating and renaming write through the server,
 * which normalizes and validates. A tag with customers is deactivated (it stops being offered and they keep it); only an unused one can be deleted. Tags that differ
 * only by an accent are listed for review, never merged.
 */
export default function TagsPanel({ admin }) {
    const confirm = useConfirm();
    const [search, setSearch] = useState(admin.search ?? '');
    const [renaming, setRenaming] = useState(null);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return undefined;
        }

        const timer = setTimeout(() => router.get(window.location.pathname, { ...Object.fromEntries(new URLSearchParams(window.location.search)), tag_search: search || undefined }, { only: ['tagAdmin'], preserveState: true, preserveScroll: true, replace: true }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const toggle = async (tag) => {
        if (tag.isActive) {
            const confirmed = await confirm({
                description: `«${tag.name}» dejará de ofrecerse a clientes nuevos. ${tag.customers > 0 ? `Los ${tag.customers} clientes que la tienen la conservan. ` : ''}Puede reactivarse.`,
                confirmLabel: 'Desactivar',
            });

            if (!confirmed) return;
        }

        router.post(route(tag.isActive ? 'customers.tags.deactivate' : 'customers.tags.activate', tag.id), {}, { preserveScroll: true });
    };

    const remove = async (tag) => {
        const confirmed = await confirm({ description: `«${tag.name}» se eliminará del catálogo. Nadie la tiene asignada. Esta acción no se puede deshacer.`, confirmLabel: 'Eliminar' });

        if (confirmed) router.delete(route('customers.tags.destroy', tag.id), { preserveScroll: true });
    };

    const actions = (tag) => (
        <div className="flex flex-wrap items-center justify-end gap-1 whitespace-nowrap">
            {admin.canEdit && (
                <>
                    <button type="button" onClick={() => setRenaming(tag.id)} aria-label={`Renombrar ${tag.name}`} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                        <Pencil className="size-4" aria-hidden="true" />
                        Renombrar
                    </button>
                    <button type="button" onClick={() => toggle(tag)} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        {tag.isActive ? 'Desactivar' : 'Activar'}
                    </button>
                    {tag.customers === 0 && (
                        <button type="button" onClick={() => remove(tag)} aria-label={`Eliminar ${tag.name}`} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-danger hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-primary">
                            <Trash2 className="size-4" aria-hidden="true" />
                            Eliminar
                        </button>
                    )}
                </>
            )}
        </div>
    );

    const name = (tag) => (renaming === tag.id ? <RenameTag tag={tag} onDone={() => setRenaming(null)} /> : <span className="font-semibold text-ink">{tag.name}</span>);
    const state = (tag) => <Badge tone={tag.isActive ? 'green' : 'red'}>{tag.isActive ? 'Activa' : 'Inactiva'}</Badge>;
    const count = (tag) => (tag.customers > 0 ? <Link href={route('customers.index', { tag: tag.name })} className="hover:text-primary hover:underline">{customersLabel(tag.customers)}</Link> : customersLabel(0));

    return (
        <Card className="overflow-hidden">
            <div className="space-y-4 border-b border-line p-4 sm:p-6">
                {admin.canCreate && <NewTag />}
                {admin.possibleDuplicates.length > 0 && (
                    <Alert tone="warning">
                        Revisa estas etiquetas que solo se diferencian por tildes; Ava no las une por su cuenta: {admin.possibleDuplicates.map((group) => group.map((name) => `«${name}»`).join(' y ')).join('; ')}.
                    </Alert>
                )}
                <SearchInput value={search} onChange={setSearch} placeholder="Buscar etiqueta" label="Buscar etiquetas" />
            </div>

            {admin.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Tag} title={admin.search ? 'Ninguna etiqueta coincide' : 'Todavía no hay etiquetas'} description={admin.search ? 'Prueba con otra búsqueda.' : 'Crea la primera para poder asignarla a tus clientes.'} />
                </div>
            ) : (
                <>
                    <ul className="divide-y divide-line lg:hidden" aria-label="Etiquetas">
                        {admin.data.map((tag) => (
                            <li key={tag.id} className={`space-y-2 p-4 ${tag.isActive ? '' : 'bg-canvas/50'}`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">{name(tag)}</div>
                                    {state(tag)}
                                </div>
                                <p className="text-xs text-ink-muted">{count(tag)}</p>
                                {actions(tag)}
                            </li>
                        ))}
                    </ul>

                    <div className="hidden lg:block">
                        <TableWrap>
                            <thead>
                                <tr>
                                    <Th>Etiqueta</Th>
                                    <Th>Clientes</Th>
                                    <Th>Estado</Th>
                                    <Th className="text-right">Acciones</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {admin.data.map((tag) => (
                                    <tr key={tag.id} className={`transition-colors duration-150 hover:bg-canvas/70 focus-within:bg-canvas/70 ${tag.isActive ? '' : 'bg-canvas/40'}`}>
                                        <Td>{name(tag)}</Td>
                                        <Td className="text-ink-muted">{count(tag)}</Td>
                                        <Td>{state(tag)}</Td>
                                        <Td>{actions(tag)}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </TableWrap>
                    </div>
                </>
            )}
        </Card>
    );
}
