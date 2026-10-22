import { router, useForm } from '@inertiajs/react';
import { Eye, EyeOff, FolderPlus, Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';
import ImplementationBadge from './ImplementationBadge';
import { modulesBySection } from './ModuleChecklist';

function NameDescriptionFields({ form, prefix, nameLabel }) {
    return (
        <>
            <div>
                <InputLabel htmlFor={`${prefix}_name`} value={nameLabel} />
                <TextInput id={`${prefix}_name`} className="mt-1" value={form.data.name} maxLength={120} onChange={(e) => form.setData('name', e.target.value)} invalid={Boolean(form.errors.name)} required />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor={`${prefix}_description`} value="Descripción (opcional)" />
                <Textarea id={`${prefix}_description`} className="mt-1" value={form.data.description} maxLength={500} onChange={(e) => form.setData('description', e.target.value)} invalid={Boolean(form.errors.description)} />
                <InputError message={form.errors.description} className="mt-1" />
            </div>
        </>
    );
}

function FormModal({ show, title, form, onClose, onSubmit, submitLabel, children }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <form onSubmit={onSubmit} className="p-6">
                <h2 className="text-lg font-bold text-ink">{title}</h2>
                <div className="mt-4 space-y-4">{children}</div>
                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {submitLabel}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/**
 * Catálogo de módulos tab: the modules plans are built from, organized like the application menu (General, Operación, Administración; the server says where each
 * one sits), with the categories (the commercial or organizational label of each module) managed in their own card. Registering a module or a category only
 * reserves its place; whether a module works comes from the platform's code (the badge), never from this screen. Nothing is deleted.
 */
export default function CatalogPanel({ catalog, can }) {
    const confirm = useConfirm();
    const toast = useToast();

    const moduleForm = useForm({ name: '', description: '', module_category_id: '' });
    const categoryForm = useForm({ name: '', description: '' });
    // `null` = closed; `{}` = creating; a record = editing it.
    const [moduleDialog, setModuleDialog] = useState(null);
    const [categoryDialog, setCategoryDialog] = useState(null);

    const categoryOptions = catalog
        .filter((category) => category.isActive || category.id === moduleDialog?.categoryId)
        .map((category) => ({ value: category.id, label: category.name }));

    const openModule = (module = {}, categoryId = '') => {
        moduleForm.clearErrors();
        moduleForm.setData({ name: module.name ?? '', description: module.description ?? '', module_category_id: module.categoryId ?? categoryId });
        setModuleDialog(module.id ? module : {});
    };

    const openCategory = (category = {}) => {
        categoryForm.clearErrors();
        categoryForm.setData({ name: category.name ?? '', description: category.description ?? '' });
        setCategoryDialog(category.id ? category : {});
    };

    const save = (form, dialog, close, routes) => (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => close(null) };

        if (dialog.id) form.put(route(routes.update, dialog.id), options);
        else form.post(route(routes.store), options);
    };

    const toggle = async (record, kind) => {
        const deactivating = record.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? kind === 'module'
                    ? `«${record.name}» no podrá añadirse a planes nuevos. Se conservan su registro y los planes que ya lo incluyen no cambian de datos.`
                    : `«${record.name}» dejará de ofrecerse para registrar módulos nuevos. Sus módulos actuales no cambian.`
                : `«${record.name}» volverá a estar disponible.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        const base = kind === 'module' ? 'modules' : 'module-categories';
        router.post(route(`${base}.${deactivating ? 'deactivate' : 'activate'}`, record.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.status ?? 'No se pudo cambiar el estado.'),
        });
    };

    // Shows or hides a solution in the PUBLIC offer. It deletes nothing: plans, Workspaces and subscriptions keep working; the server validates it again.
    const togglePublic = async (category) => {
        const hiding = category.isPublic;
        const confirmed = await confirm({
            title: hiding ? 'Ocultar de la oferta pública' : 'Mostrar en la oferta pública',
            description: hiding
                ? `«${category.name}» dejará de ofrecerse a nuevos clientes en el registro público. Sus planes, Workspaces y suscripciones no cambian.`
                : `«${category.name}» se ofrecerá en el registro público con sus planes que estén activos, públicos y completos.`,
            confirmLabel: hiding ? 'Ocultar' : 'Mostrar',
        });

        if (!confirmed) return;

        router.post(route(hiding ? 'module-categories.unpublish' : 'module-categories.publish', category.id), {}, {
            preserveScroll: true,
            onError: (errors) => toast.error(errors.category ?? 'No se pudo cambiar la visibilidad.'),
        });
    };

    return (
        <div className="space-y-6">
            <Alert tone="info">
                Registrar un módulo o una categoría reserva su lugar para futuras ofertas, pero <strong>no desarrolla su funcionalidad</strong>. Un módulo figura como
                «Implementado» solo cuando su funcionalidad ya existe en la plataforma; los demás quedan «Pendientes de implementación» y no se ofrecen como operativos.
            </Alert>

            {can.createModule && (
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <SecondaryButton type="button" onClick={() => openCategory()} className="gap-2">
                        <FolderPlus className="size-4" aria-hidden="true" />
                        Nueva categoría
                    </SecondaryButton>
                    <PrimaryButton type="button" onClick={() => openModule()} className="gap-2" disabled={categoryOptions.length === 0}>
                        <Plus className="size-4" aria-hidden="true" />
                        Registrar módulo pendiente
                    </PrimaryButton>
                </div>
            )}

            <Card className="overflow-hidden">
                <div className="border-b border-line p-4 sm:px-6">
                    <h2 className="text-base font-bold text-ink">Categorías</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Agrupan los módulos por solución u organización (por ejemplo Servicios y Reservas). Los módulos de abajo se muestran como en el menú de la aplicación; la categoría es una etiqueta más.</p>
                </div>
                <ul className="divide-y divide-line">
                    {catalog.map((category) => (
                        <li key={category.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                                    {category.name}
                                    <Badge tone={category.isActive ? 'green' : 'neutral'}>{category.isActive ? 'Activa' : 'Inactiva'}</Badge>
                                    <Badge tone={category.isSolution ? 'violet' : 'neutral'}>{category.isSolution ? 'Solución comercial' : 'Capacidades compartidas'}</Badge>
                                    {category.isSolution && category.isPublic !== null && <Badge tone={category.isPublic ? 'green' : 'amber'}>{category.isPublic ? 'Pública' : 'Privada'}</Badge>}
                                    <span className="text-xs font-normal text-ink-muted">{category.modules.length} {category.modules.length === 1 ? 'módulo' : 'módulos'}</span>
                                </p>
                                {category.description && <p className="truncate text-xs text-ink-muted">{category.description}</p>}
                            </div>
                            <div className="flex items-center gap-1">
                                {can.createModule && category.isActive && <IconButton icon={Plus} label="Registrar módulo aquí" context={category.name} onClick={() => openModule({}, category.id)} />}
                                {can.activateModule && category.isSolution && category.isPublic !== null && category.isActive && <IconButton icon={category.isPublic ? EyeOff : Eye} label={category.isPublic ? 'Ocultar de la oferta pública' : 'Mostrar en la oferta pública'} context={category.name} onClick={() => togglePublic(category)} />}
                                {can.editModule && <IconButton icon={Pencil} label="Editar categoría" context={category.name} onClick={() => openCategory(category)} />}
                                {can.activateModule && <IconButton icon={Power} label={category.isActive ? 'Desactivar' : 'Activar'} context={category.name} tone={category.isActive ? 'danger' : 'primary'} onClick={() => toggle(category, 'category')} />}
                            </div>
                        </li>
                    ))}
                </ul>
            </Card>

            {modulesBySection(catalog).map(({ section, modules }) => (
                <Card key={section.key} className="overflow-hidden">
                    <div className="border-b border-line p-4 sm:px-6">
                        <h2 className="flex flex-wrap items-center gap-2 text-base font-bold text-ink">
                            {section.name}
                            <span className="text-xs font-medium text-ink-muted">{modules.length} {modules.length === 1 ? 'módulo' : 'módulos'}</span>
                        </h2>
                        {section.description && <p className="mt-0.5 text-sm text-ink-muted">{section.description}</p>}
                    </div>

                    <ul className="divide-y divide-line">
                        {modules.map((module) => (
                            <li key={module.id} className="flex flex-wrap items-start justify-between gap-3 p-4 sm:px-6">
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                                        {module.name}
                                        <ImplementationBadge implemented={module.implemented} />
                                        <Badge tone="neutral" className="normal-case tracking-normal">{module.category.name}</Badge>
                                        <Badge tone="violet">{module.group.name}</Badge>
                                        {module.alsoFor.length > 0 && <Badge tone="blue">También en {module.alsoFor.map((solution) => solution.name).join(', ')}</Badge>}
                                        {!module.isActive && <Badge tone="neutral">Inactivo</Badge>}
                                    </p>
                                    {module.description && <p className="mt-0.5 text-sm text-ink-muted">{module.description}</p>}
                                    <p className="mt-1 text-xs text-ink-muted">
                                        <code className="rounded bg-canvas px-1.5 py-0.5">{module.key}</code> · en {module.plansCount} {module.plansCount === 1 ? 'plan' : 'planes'}
                                        {module.requires.length > 0 && <> · necesita {module.requires.map((required) => required.name).join(', ')}</>}
                                    </p>
                                </div>
                                <div className="flex items-center gap-1">
                                    {can.editModule && <IconButton icon={Pencil} label="Editar" context={module.name} onClick={() => openModule(module)} />}
                                    {can.activateModule && <IconButton icon={Power} label={module.isActive ? 'Desactivar' : 'Activar'} context={module.name} tone={module.isActive ? 'danger' : 'primary'} onClick={() => toggle(module, 'module')} />}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            ))}

            <FormModal
                show={moduleDialog !== null}
                title={moduleDialog?.id ? 'Editar módulo' : 'Registrar módulo'}
                form={moduleForm}
                submitLabel={moduleDialog?.id ? 'Guardar cambios' : 'Registrar módulo'}
                onClose={() => setModuleDialog(null)}
                onSubmit={save(moduleForm, moduleDialog ?? {}, setModuleDialog, { store: 'modules.store', update: 'modules.update' })}
            >
                <NameDescriptionFields form={moduleForm} prefix="module" nameLabel="Nombre del módulo" />
                <div>
                    <InputLabel htmlFor="module_category" value="Categoría" />
                    <Select id="module_category" className="mt-1" value={moduleForm.data.module_category_id} onChange={(value) => moduleForm.setData('module_category_id', value)} options={categoryOptions} placeholder="Elige una categoría" invalid={Boolean(moduleForm.errors.module_category_id)} />
                    <InputError message={moduleForm.errors.module_category_id} className="mt-1" />
                </div>
                {moduleDialog?.id && (
                    <p className="text-xs text-ink-muted">
                        Identificador técnico: <code className="rounded bg-canvas px-1.5 py-0.5">{moduleDialog.key}</code> (no cambia aunque cambies el nombre o la categoría).
                    </p>
                )}
            </FormModal>

            <FormModal
                show={categoryDialog !== null}
                title={categoryDialog?.id ? 'Editar categoría' : 'Nueva categoría'}
                form={categoryForm}
                submitLabel={categoryDialog?.id ? 'Guardar cambios' : 'Crear categoría'}
                onClose={() => setCategoryDialog(null)}
                onSubmit={save(categoryForm, categoryDialog ?? {}, setCategoryDialog, { store: 'module-categories.store', update: 'module-categories.update' })}
            >
                <NameDescriptionFields form={categoryForm} prefix="category" nameLabel="Nombre comercial de la categoría o solución" />
                {categoryDialog?.key && (
                    <p className="mt-3 text-xs text-ink-muted">
                        Identificador técnico: <code className="rounded bg-canvas px-1.5 py-0.5">{categoryDialog.key}</code> (no cambia aunque cambies el nombre; los planes y módulos se asocian por él). Desactivarla la retira de la oferta pública sin borrar planes ni contrataciones.
                    </p>
                )}
            </FormModal>
        </div>
    );
}
