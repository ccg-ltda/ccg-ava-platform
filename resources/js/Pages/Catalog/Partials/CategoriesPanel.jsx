import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Tag } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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

const productsLabel = (count) => (count === 0 ? 'Sin productos' : `${count.toLocaleString('es')} producto${count === 1 ? '' : 's'}`);

/** Creates a category. The server normalizes the name (capitalization and spaces) and refuses an empty or repeated one; the message shows under the field. */
function NewCategory() {
    const form = useForm({ name: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('catalog.categories.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
            <div>
                <InputLabel htmlFor="new_category_name" value="Nueva categoría" />
                <TextInput id="new_category_name" className="mt-1" value={form.data.name} maxLength={80} placeholder="Ej. Cuidado facial" onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <PrimaryButton type="submit" disabled={form.processing} className="gap-2 sm:mb-0 sm:self-start sm:mt-[1.375rem]">
                <Plus className="size-4" aria-hidden="true" />
                Crear categoría
            </PrimaryButton>
        </form>
    );
}

/** Renames one category in place (the products that use it follow, they are linked by id). */
function RenameCategory({ category, onDone }) {
    const form = useForm({ name: category.name });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('catalog.categories.update', category.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-wrap items-start gap-2">
            <div className="min-w-0 flex-1 basis-48">
                <TextInput aria-label={`Nombre de la categoría ${category.name}`} autoFocus value={form.data.name} maxLength={80} onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} />
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
 * Tab «Categorías» of the catalog: the categories of the Workspace (active or not) with the products that use each one. Creating and renaming write through
 * the server, which normalizes and validates; a category is never deleted (it is deactivated: it stops being offered to new products and the ones that have it
 * keep it).
 */
export default function CategoriesPanel({ admin }) {
    const confirm = useConfirm();
    const [search, setSearch] = useState(admin.search ?? '');
    const [renaming, setRenaming] = useState(null);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return undefined;
        }

        const timer = setTimeout(() => router.get(window.location.pathname, { ...Object.fromEntries(new URLSearchParams(window.location.search)), category_search: search || undefined }, { only: ['categoryAdmin'], preserveState: true, preserveScroll: true, replace: true }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const toggle = async (category) => {
        if (category.isActive) {
            const confirmed = await confirm({
                description: `«${category.name}» dejará de ofrecerse a productos nuevos. ${category.products > 0 ? `Los ${category.products} productos que la tienen la conservan. ` : ''}Puede reactivarse.`,
                confirmLabel: 'Desactivar',
            });

            if (!confirmed) return;
        }

        router.post(route(category.isActive ? 'catalog.categories.deactivate' : 'catalog.categories.activate', category.id), {}, { preserveScroll: true });
    };

    const actions = (category) => (
        <div className="flex flex-wrap items-center justify-end gap-1 whitespace-nowrap">
            {admin.canEdit && (
                <>
                    <button type="button" onClick={() => setRenaming(category.id)} aria-label={`Renombrar ${category.name}`} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                        <Pencil className="size-4" aria-hidden="true" />
                        Renombrar
                    </button>
                    <button type="button" onClick={() => toggle(category)} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        {category.isActive ? 'Desactivar' : 'Activar'}
                    </button>
                </>
            )}
        </div>
    );

    const name = (category) => (renaming === category.id ? <RenameCategory category={category} onDone={() => setRenaming(null)} /> : <span className="font-semibold text-ink">{category.name}</span>);
    const state = (category) => <Badge tone={category.isActive ? 'green' : 'red'}>{category.isActive ? 'Activa' : 'Inactiva'}</Badge>;

    return (
        <Card className="overflow-hidden">
            <div className="space-y-4 border-b border-line p-4 sm:p-6">
                {admin.canCreate && <NewCategory />}
                <SearchInput value={search} onChange={setSearch} placeholder="Buscar categoría" label="Buscar categorías" />
            </div>

            {admin.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Tag} title={admin.search ? 'Ninguna categoría coincide' : 'Todavía no hay categorías'} description={admin.search ? 'Prueba con otra búsqueda.' : 'Crea la primera para poder asignarla a tus productos.'} />
                </div>
            ) : (
                <>
                    <ul className="divide-y divide-line lg:hidden" aria-label="Categorías">
                        {admin.data.map((category) => (
                            <li key={category.id} className={`space-y-2 p-4 ${category.isActive ? '' : 'bg-canvas/50'}`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">{name(category)}</div>
                                    {state(category)}
                                </div>
                                <p className="text-xs text-ink-muted">{productsLabel(category.products)}</p>
                                {actions(category)}
                            </li>
                        ))}
                    </ul>

                    <div className="hidden lg:block">
                        <TableWrap>
                            <thead>
                                <tr>
                                    <Th>Categoría</Th>
                                    <Th>Productos</Th>
                                    <Th>Estado</Th>
                                    <Th className="text-right">Acciones</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {admin.data.map((category) => (
                                    <tr key={category.id} className={`transition-colors duration-150 hover:bg-canvas/70 focus-within:bg-canvas/70 ${category.isActive ? '' : 'bg-canvas/40'}`}>
                                        <Td>{name(category)}</Td>
                                        <Td className="text-ink-muted">{productsLabel(category.products)}</Td>
                                        <Td>{state(category)}</Td>
                                        <Td>{actions(category)}</Td>
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
