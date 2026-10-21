import { X } from 'lucide-react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import ModuleChecklist, { unmetRequirements } from './ModuleChecklist';

/**
 * Create / edit a plan in a wide dialog with a bounded height: the header and the footer (summary and actions) stay in view while the two sections —
 * 1. the plan's data and 2. its modules, organized like the application menu— scroll inside. `form` is an Inertia useForm with `name`, `description`
 * and `module_ids`; the server saves exactly what it holds. Pass `catalog` to offer the modules.
 */
export default function PlanFormModal({ show, title, form, catalog, submitLabel, onClose, onSubmit }) {
    const modules = catalog ? catalog.flatMap((category) => category.modules) : [];
    const selected = form.data.module_ids ?? [];
    const chosen = modules.filter((module) => selected.includes(module.id));
    const incomplete = catalog ? unmetRequirements(catalog, selected).length > 0 : false;
    const pending = chosen.filter((module) => !module.implemented);

    return (
        <Modal show={show} onClose={onClose} maxWidth={catalog ? '6xl' : 'lg'} scrollable>
            <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col" aria-label={title}>
                <header className="flex shrink-0 items-start justify-between gap-3 border-b border-line p-4 sm:px-6">
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-ink">{title}</h2>
                        {catalog && <p className="text-xs text-ink-muted">Dos pasos: los datos del plan y los módulos que incluye.</p>}
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="rounded-md p-1.5 text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                        <X className="size-5" aria-hidden="true" />
                    </button>
                </header>

                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-4 sm:px-6" tabIndex={0} aria-label="Contenido del plan">
                    <section aria-labelledby="plan-step-data" className="space-y-4">
                        <h3 id="plan-step-data" className="flex items-center gap-2 text-sm font-bold text-ink">
                            <span className="grid size-6 place-items-center rounded-full bg-primary text-xs text-white" aria-hidden="true">1</span>
                            Datos del plan
                        </h3>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="plan_name" value="Nombre del plan" />
                                <TextInput id="plan_name" className="mt-1" value={form.data.name} maxLength={120} onChange={(e) => form.setData('name', e.target.value)} invalid={Boolean(form.errors.name)} required />
                                <InputError message={form.errors.name} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="plan_description" value="Descripción (opcional)" />
                                <Textarea id="plan_description" className="mt-1" rows={2} value={form.data.description} maxLength={1000} onChange={(e) => form.setData('description', e.target.value)} invalid={Boolean(form.errors.description)} />
                                <InputError message={form.errors.description} className="mt-1" />
                            </div>
                        </div>
                    </section>

                    {catalog && (
                        <section aria-labelledby="plan-step-modules" className="space-y-4">
                            <div>
                                <h3 id="plan-step-modules" className="flex items-center gap-2 text-sm font-bold text-ink">
                                    <span className="grid size-6 place-items-center rounded-full bg-primary text-xs text-white" aria-hidden="true">2</span>
                                    Módulos incluidos
                                </h3>
                                <p className="mt-1 text-xs text-ink-muted">
                                    Los módulos decidieron qué existe para el Workspace; los permisos de cada rol deciden qué hace cada persona en ellos. Son cosas independientes.
                                </p>
                            </div>
                            {pending.length > 0 && (
                                <Alert tone="info">
                                    {pending.length === 1 ? 'Un módulo seleccionado' : `${pending.length} módulos seleccionados`} todavía no tiene funcionalidad (pendiente de implementación): se reserva en el plan pero no opera.
                                </Alert>
                            )}
                            <ModuleChecklist catalog={catalog} selected={selected} onChange={(ids) => form.setData('module_ids', ids)} error={form.errors.module_ids} idPrefix="plan_module" />
                        </section>
                    )}
                </div>

                <footer className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-line p-4 sm:px-6">
                    <p role="status" className="text-xs text-ink-muted">
                        {catalog ? (
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
                            {submitLabel}
                        </PrimaryButton>
                    </div>
                </footer>
            </form>
        </Modal>
    );
}
