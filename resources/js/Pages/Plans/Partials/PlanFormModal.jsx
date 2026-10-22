import { Boxes, FileText, Gauge, Tag, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Tabs from '@/Components/Tabs';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import LimitsSection from './LimitsSection';
import ModuleChecklist, { unmetRequirements } from './ModuleChecklist';
import OfferSection from './OfferSection';
import SectionCard from './SectionCard';

/** Keys of the form (as the server names its errors) that belong to each tab, to flag the tab that holds an error. */
const TAB_ERRORS = {
    datos: ['name', 'description', 'module_category_id'],
    oferta: ['offer', 'price_monthly', 'price_yearly', 'currency', 'is_public', 'requires_quote'],
    limites: ['limits', 'limits.users'],
    modulos: ['module_ids'],
};

/**
 * Create / edit a plan in a wide dialog with inner tabs — Datos, Oferta comercial, Límites and Módulos — and a fixed footer with Cancelar / Guardar. The form
 * state lives in `form` (an Inertia useForm), outside the tabs, so changing tab never loses anything and ONE save sends everything the person typed. A tab
 * with an error is flagged. `catalog` offers the modules (without it the dialog is the short form); `commercial` brings the solutions, currencies, the
 * limit metrics and whether the database is ready for the commercial fields (when it is not, the editor says so and does not send them).
 */
export default function PlanFormModal({ show, title, form, catalog, commercial, status, submitLabel, onClose, onSubmit }) {
    const [tab, setTab] = useState(0);
    const modules = catalog ? catalog.flatMap((category) => category.modules) : [];
    const selected = form.data.module_ids ?? [];
    const chosen = modules.filter((module) => selected.includes(module.id));
    const incomplete = catalog ? unmetRequirements(catalog, selected).length > 0 : false;
    const pending = chosen.filter((module) => !module.implemented);
    const ready = commercial?.ready !== false;
    const limitsReady = commercial?.limitsReady !== false;

    // Never send what the database can not store yet: the person is told, nothing is half saved in silence.
    useEffect(() => {
        form.transform((data) => {
            const out = { ...data };

            if (!ready) ['module_category_id', 'price_monthly', 'price_yearly', 'currency', 'is_public', 'requires_quote'].forEach((key) => delete out[key]);
            if (!limitsReady) delete out.limits;

            return out;
        });
    }, [ready, limitsReady]);

    useEffect(() => {
        if (show) setTab(0);
    }, [show]);

    const hasError = (tabKey) => TAB_ERRORS[tabKey].some((key) => form.errors[key]);
    const flag = (label, tabKey) => (
        <span className="inline-flex items-center gap-1.5">
            {label}
            {hasError(tabKey) && <span className="size-2 rounded-full bg-danger" role="img" aria-label="tiene errores" />}
        </span>
    );

    const tabs = [
        { id: 'datos', label: flag('Datos del plan', 'datos'), icon: FileText },
        commercial && { id: 'oferta', label: flag('Oferta comercial', 'oferta'), icon: Tag },
        commercial && { id: 'limites', label: flag('Límites', 'limites'), icon: Gauge },
        catalog && { id: 'modulos', label: flag('Módulos incluidos', 'modulos'), icon: Boxes, count: chosen.length },
    ].filter(Boolean);

    const panels = tabs.map(({ id }) => {
        if (id === 'datos') {
            return (
                <SectionCard key={id} id="plan-card-data" title="Datos del plan" description="Cómo se llama el plan y bajo qué solución se ofrece.">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="plan_name" value="Nombre del plan" />
                            <TextInput id="plan_name" className="mt-1" value={form.data.name} maxLength={120} onChange={(e) => form.setData('name', e.target.value)} invalid={Boolean(form.errors.name)} required />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>
                        {commercial && (
                            <div>
                                <InputLabel htmlFor="plan_solution" value="Solución" />
                                <Select id="plan_solution" className="mt-1" value={form.data.module_category_id ?? ''} disabled={!ready} onChange={(value) => form.setData('module_category_id', value === '' ? null : value)} options={[{ value: '', label: 'Sin solución (personalizado)' }, ...commercial.solutions]} />
                                <InputError message={form.errors.module_category_id} className="mt-1" />
                            </div>
                        )}
                        <div className="lg:col-span-2">
                            <InputLabel htmlFor="plan_description" value="Descripción (opcional)" />
                            <Textarea id="plan_description" className="mt-1" rows={3} value={form.data.description} maxLength={1000} onChange={(e) => form.setData('description', e.target.value)} invalid={Boolean(form.errors.description)} />
                            <InputError message={form.errors.description} className="mt-1" />
                        </div>
                    </div>
                    {status !== undefined && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span className="font-medium text-ink">Estado:</span>
                            <Badge tone={status ? 'green' : 'neutral'}>{status ? 'Activo' : 'Inactivo'}</Badge>
                            <span className="text-xs text-ink-muted">Se cambia con «Activar» o «Desactivar» en el plan; un plan con Workspaces no puede desactivarse.</span>
                        </div>
                    )}
                </SectionCard>
            );
        }

        if (id === 'oferta') return <OfferSection key={id} form={form} commercial={commercial} ready={ready} />;
        if (id === 'limites') return <LimitsSection key={id} form={form} commercial={commercial} ready={limitsReady} />;

        return (
            <SectionCard key={id} id="plan-card-modules" title="Módulos incluidos" description="Los módulos deciden qué existe para el Workspace; los permisos de cada rol deciden qué hace cada persona en ellos. Son cosas independientes.">
                {pending.length > 0 && (
                    <Alert tone="info">
                        {pending.length === 1 ? 'Un módulo seleccionado' : `${pending.length} módulos seleccionados`} todavía no tiene funcionalidad (pendiente de implementación): se reserva en el plan pero no opera.
                    </Alert>
                )}
                <ModuleChecklist catalog={catalog} selected={selected} onChange={(ids) => form.setData('module_ids', ids)} error={form.errors.module_ids} idPrefix="plan_module" />
            </SectionCard>
        );
    });

    return (
        <Modal show={show} onClose={onClose} maxWidth={catalog ? '7xl' : '4xl'} scrollable>
            <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col" aria-label={title}>
                <header className="flex shrink-0 items-start justify-between gap-3 border-b border-line p-4 sm:px-6">
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-ink">{title}</h2>
                        <p className="text-xs text-ink-muted">Un solo guardado para todas las pestañas: cambiar de pestaña no pierde nada.</p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="rounded-md p-1.5 text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                        <X className="size-5" aria-hidden="true" />
                    </button>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:px-6" tabIndex={0} aria-label="Contenido del plan">
                    {!ready && (
                        <Alert tone="warning" className="mb-4">
                            La oferta comercial (solución, precios y publicación) todavía no se puede guardar: a esta base de datos le faltan migraciones por aplicar. Puedes guardar el nombre, la descripción y los módulos.
                        </Alert>
                    )}
                    <Tabs tabs={tabs} selectedIndex={Math.min(tab, tabs.length - 1)} onChange={setTab}>
                        {panels}
                    </Tabs>
                </div>

                <footer className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-line p-4 sm:px-6">
                    <p role="status" className="text-xs text-ink-muted">
                        {form.hasErrors ? (
                            <span className="font-semibold text-danger">Revisa las pestañas marcadas: hay campos con errores y no se guardó nada.</span>
                        ) : catalog ? (
                            <>
                                <strong className="text-ink">{chosen.length}</strong> de {modules.length} módulos seleccionados{incomplete ? ' · faltan dependencias' : ''}
                            </>
                        ) : null}
                    </p>
                    <div className="flex gap-3">
                        <SecondaryButton type="button" onClick={onClose}>
                            Cancelar
                        </SecondaryButton>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Guardando…' : submitLabel}
                        </PrimaryButton>
                    </div>
                </footer>
            </form>
        </Modal>
    );
}
