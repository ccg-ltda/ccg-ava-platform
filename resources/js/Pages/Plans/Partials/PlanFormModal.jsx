import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import ModuleChecklist, { unmetRequirements } from './ModuleChecklist';

/**
 * Name, description and (when creating) the modules of a plan. The same form serves both: pass `catalog` to offer the module
 * checklist. `form` is an Inertia useForm with `name` and `description` (and `module_ids` when there is a catalog).
 */
export default function PlanFormModal({ show, title, form, catalog, submitLabel, onClose, onSubmit }) {
    const incomplete = catalog ? unmetRequirements(catalog, form.data.module_ids).length > 0 : false;

    return (
        <Modal show={show} onClose={onClose} maxWidth={catalog ? '3xl' : 'lg'}>
            <form onSubmit={onSubmit} className="p-6">
                <h2 className="text-lg font-bold text-ink">{title}</h2>

                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel htmlFor="plan_name" value="Nombre del plan" />
                        <TextInput id="plan_name" className="mt-1" value={form.data.name} maxLength={120} onChange={(e) => form.setData('name', e.target.value)} invalid={Boolean(form.errors.name)} required />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="plan_description" value="Descripción (opcional)" />
                        <Textarea id="plan_description" className="mt-1" value={form.data.description} maxLength={1000} onChange={(e) => form.setData('description', e.target.value)} invalid={Boolean(form.errors.description)} />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>
                    {catalog && (
                        <div>
                            <p className="mb-2 text-sm font-medium text-ink">Módulos incluidos</p>
                            <ModuleChecklist catalog={catalog} selected={form.data.module_ids} onChange={(ids) => form.setData('module_ids', ids)} error={form.errors.module_ids} idPrefix="new_plan_module" />
                        </div>
                    )}
                </div>

                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing || incomplete}>
                        {submitLabel}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
