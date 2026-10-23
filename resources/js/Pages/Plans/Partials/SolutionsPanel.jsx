import { router, useForm } from '@inertiajs/react';
import { Eye, EyeOff, FolderPlus, Pencil, Power } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { useToast } from '@/Components/Toast';
import FormModal, { NameDescriptionFields } from './FormModal';

/**
 * Soluciones tab: the commercial solutions (Salud, Servicios y Reservas, Delivery...) and the groups of shared capabilities, with the name people SEE. Editing the name
 * or the description changes only what is shown (registro público, planes, contratación, listados): the technical identifier (`key`) and every relation (modules,
 * plans, Workspaces, subscriptions) stay exactly as they were. The server decides who may edit (`edit-modules`) and audits every change. Nothing is deleted:
 * a solution is deactivated, or hidden from the public offer, and its plans and contracts keep working.
 */
export default function SolutionsPanel({ catalog, can }) {
    const confirm = useConfirm();
    const toast = useToast();
    const form = useForm({ name: '', description: '' });
    // `null` = closed; `{}` = creating; a record = editing it.
    const [dialog, setDialog] = useState(null);

    const open = (category = {}) => {
        form.clearErrors();
        form.setData({ name: category.name ?? '', description: category.description ?? '' });
        setDialog(category.id ? category : {});
    };

    const save = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setDialog(null) };

        if (dialog.id) form.put(route('module-categories.update', dialog.id), options);
        else form.post(route('module-categories.store'), options);
    };

    const toggle = async (category) => {
        const deactivating = category.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `«${category.name}» dejará de ofrecerse para registrar módulos y planes nuevos. Sus módulos, planes y contratos actuales no cambian.`
                : `«${category.name}» volverá a estar disponible.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (!confirmed) return;

        router.post(route(`module-categories.${deactivating ? 'deactivate' : 'activate'}`, category.id), {}, {
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

    const solutions = catalog.filter((category) => category.isSolution);
    const shared = catalog.filter((category) => !category.isSolution);

    const row = (category) => (
        <li key={category.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div className="min-w-0">
                <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                    {category.name}
                    <Badge tone={category.isActive ? 'green' : 'neutral'}>{category.isActive ? 'Activa' : 'Inactiva'}</Badge>
                    {category.isSolution && category.isPublic !== null && <Badge tone={category.isPublic ? 'green' : 'amber'}>{category.isPublic ? 'Pública' : 'Privada'}</Badge>}
                </p>
                {category.description && <p className="mt-0.5 text-sm text-ink-muted">{category.description}</p>}
                <p className="mt-1 text-xs text-ink-muted">
                    <code className="rounded bg-canvas px-1.5 py-0.5">{category.key}</code> · {category.modules.length} {category.modules.length === 1 ? 'módulo' : 'módulos'}
                    {category.isSolution && <> · {category.plansCount} {category.plansCount === 1 ? 'plan' : 'planes'}</>}
                </p>
            </div>
            <div className="flex items-center gap-1">
                {can.activateModule && category.isSolution && category.isPublic !== null && category.isActive && <IconButton icon={category.isPublic ? EyeOff : Eye} label={category.isPublic ? 'Ocultar de la oferta pública' : 'Mostrar en la oferta pública'} context={category.name} onClick={() => togglePublic(category)} />}
                {can.editModule && <IconButton icon={Pencil} label="Editar nombre y descripción" context={category.name} onClick={() => open(category)} />}
                {can.activateModule && <IconButton icon={Power} label={category.isActive ? 'Desactivar' : 'Activar'} context={category.name} tone={category.isActive ? 'danger' : 'primary'} onClick={() => toggle(category)} />}
            </div>
        </li>
    );

    return (
        <div className="space-y-6">
            <Alert tone="info">
                Aquí cambias el <strong>nombre visible</strong> de una solución (por ejemplo «Salud» por «Asistencia médica»). El nombre se muestra en el registro público, los planes, la contratación y los listados;
                el identificador técnico de abajo no cambia, así que ningún plan, módulo, Workspace ni suscripción se ve afectado.
            </Alert>

            {can.createModule && (
                <div className="flex justify-end">
                    <PrimaryButton type="button" onClick={() => open()} className="gap-2">
                        <FolderPlus className="size-4" aria-hidden="true" />
                        Nueva solución
                    </PrimaryButton>
                </div>
            )}

            <Card className="overflow-hidden">
                <div className="border-b border-line p-4 sm:px-6">
                    <h2 className="text-base font-bold text-ink">Soluciones comerciales</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Lo que se ofrece a los clientes. Solo una solución activa y pública aparece en el registro público, con sus planes que estén activos, públicos y completos.</p>
                </div>
                <ul className="divide-y divide-line">{solutions.length === 0 ? <li className="px-4 py-3 text-sm text-ink-muted sm:px-6">Todavía no hay soluciones.</li> : solutions.map(row)}</ul>
            </Card>

            {shared.length > 0 && (
                <Card className="overflow-hidden">
                    <div className="border-b border-line p-4 sm:px-6">
                        <h2 className="text-base font-bold text-ink">Capacidades compartidas</h2>
                        <p className="mt-0.5 text-sm text-ink-muted">Grupos de módulos reutilizables que cualquier plan puede incluir. No se ofrecen como solución en el registro público.</p>
                    </div>
                    <ul className="divide-y divide-line">{shared.map(row)}</ul>
                </Card>
            )}

            <FormModal
                show={dialog !== null}
                title={dialog?.id ? 'Editar solución' : 'Nueva solución'}
                form={form}
                submitLabel={dialog?.id ? 'Guardar cambios' : 'Crear solución'}
                onClose={() => setDialog(null)}
                onSubmit={save}
            >
                <NameDescriptionFields form={form} prefix="solution" nameLabel="Nombre visible de la solución" />
                {dialog?.key && (
                    <p className="text-xs text-ink-muted">
                        Identificador técnico: <code className="rounded bg-canvas px-1.5 py-0.5">{dialog.key}</code> (no cambia aunque cambies el nombre; los planes y módulos se asocian por él). Desactivarla la retira de la oferta pública sin borrar planes ni contrataciones.
                    </p>
                )}
            </FormModal>
        </div>
    );
}
