import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import { useToast } from '@/Components/Toast';
import FormModal, { NameDescriptionFields } from './FormModal';
import ImplementationBadge from './ImplementationBadge';
import { modulesBySection } from './ModuleChecklist';

/**
 * Catálogo de módulos tab: the modules plans are built from, organized like the application menu (General, Operación, Administración; the server says where each
 * one sits). The solutions and categories they belong to are managed in the Soluciones tab. Registering a module only reserves its place; whether a module
 * works comes from the platform's code (the badge), never from this screen. Nothing is deleted.
 */
export default function CatalogPanel({ catalog, can }) {
    const confirm = useConfirm();
    const toast = useToast();

    const moduleForm = useForm({ name: '', description: '', module_category_id: '' });
    // `null` = closed; `{}` = creating; a record = editing it.
    const [moduleDialog, setModuleDialog] = useState(null);

    const categoryOptions = catalog
        .filter((category) => category.isActive || category.id === moduleDialog?.categoryId)
        .map((category) => ({ value: category.id, label: category.name }));

    const openModule = (module = {}, categoryId = '') => {
        moduleForm.clearErrors();
        moduleForm.setData({ name: module.name ?? '', description: module.description ?? '', module_category_id: module.categoryId ?? categoryId });
        setModuleDialog(module.id ? module : {});
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

    return (
        <div className="space-y-6">
            <Alert tone="info">
                Registrar un módulo reserva su lugar para futuras ofertas, pero <strong>no desarrolla su funcionalidad</strong>. Un módulo figura como
                «Implementado» solo cuando su funcionalidad ya existe en la plataforma; los demás quedan «Pendientes de implementación» y no se ofrecen como operativos.
            </Alert>

            {can.createModule && (
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <PrimaryButton type="button" onClick={() => openModule()} className="gap-2" disabled={categoryOptions.length === 0}>
                        <Plus className="size-4" aria-hidden="true" />
                        Registrar módulo pendiente
                    </PrimaryButton>
                </div>
            )}

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
        </div>
    );
}
