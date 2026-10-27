import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Tag } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

const servicesLabel = (count) => (count === 0 ? 'Sin servicios' : `${count.toLocaleString('es')} servicio${count === 1 ? '' : 's'}`);

function NewCategory() {
    const form = useForm({ name: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('services.categories.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} noValidate className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
            <div>
                <InputLabel htmlFor="new_service_category" value="Nueva categoría" />
                <TextInput id="new_service_category" className="mt-1" value={form.data.name} maxLength={80} placeholder="Ej. Coloración" onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <PrimaryButton type="submit" disabled={form.processing} className="gap-2 sm:mt-[1.375rem]">
                <Plus className="size-4" aria-hidden="true" />
                Crear
            </PrimaryButton>
        </form>
    );
}

function RenameCategory({ category, onDone }) {
    const form = useForm({ name: category.name });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('services.categories.update', category.id), { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-wrap items-start gap-2">
            <div className="min-w-0 flex-1 basis-40">
                <TextInput aria-label={`Nombre de la categoría ${category.name}`} autoFocus value={form.data.name} maxLength={80} onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <PrimaryButton type="submit" disabled={form.processing}>Guardar</PrimaryButton>
            <SecondaryButton type="button" onClick={onDone}>Cancelar</SecondaryButton>
        </form>
    );
}

/**
 * The categories of the services of the Workspace in a window (the same pattern as the tab «Categorías» of the Catálogo): create, rename, switch off. The server
 * normalizes and validates; a category is never deleted (switching it off stops it from being offered to new assignments, the services that have it keep it).
 */
export default function CategoriesModal({ admin, onClose }) {
    const confirm = useConfirm();
    const [renaming, setRenaming] = useState(null);

    const toggle = async (category) => {
        if (category.isActive) {
            const confirmed = await confirm({
                description: `«${category.name}» dejará de ofrecerse a servicios nuevos. ${category.services > 0 ? `Los ${category.services} servicios que la tienen la conservan. ` : ''}Puede reactivarse.`,
                confirmLabel: 'Desactivar',
            });

            if (!confirmed) return;
        }

        router.post(route(category.isActive ? 'services.categories.deactivate' : 'services.categories.activate', category.id), {}, { preserveScroll: true });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">Categorías de servicios</h2>
                    <p className="mt-0.5 text-xs text-ink-muted">Cada servicio elige una de esta lista; así los reportes no se mezclan por errores de escritura.</p>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-8">
                    {admin.canCreate && <NewCategory />}
                    {admin.data.length === 0 ? (
                        <EmptyState icon={Tag} title="Todavía no hay categorías" description="Crea la primera para poder asignarla a tus servicios." />
                    ) : (
                        <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Categorías">
                            {admin.data.map((category) => (
                                <li key={category.id} className={`flex flex-wrap items-center justify-between gap-2 p-3 ${category.isActive ? '' : 'bg-canvas/50'}`}>
                                    <div className="min-w-0 flex-1 basis-48">
                                        {renaming === category.id ? <RenameCategory category={category} onDone={() => setRenaming(null)} /> : <span className="font-semibold text-ink">{category.name}</span>}
                                        <p className="text-xs text-ink-muted">{servicesLabel(category.services)}</p>
                                    </div>
                                    <Badge tone={category.isActive ? 'green' : 'red'}>{category.isActive ? 'Activa' : 'Inactiva'}</Badge>
                                    {admin.canEdit && renaming !== category.id && (
                                        <div className="flex items-center gap-1">
                                            <button type="button" onClick={() => setRenaming(category.id)} aria-label={`Renombrar ${category.name}`} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                                                <Pencil className="size-4" aria-hidden="true" />
                                                Renombrar
                                            </button>
                                            <button type="button" onClick={() => toggle(category)} className="rounded-lg px-2.5 py-1.5 text-sm font-semibold text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                                                {category.isActive ? 'Desactivar' : 'Activar'}
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
