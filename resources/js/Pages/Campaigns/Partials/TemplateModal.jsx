import { useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import MessageEditor from './MessageEditor';

/** Create / edit a reusable message template, with the variable preview. */
export default function TemplateModal({ template, variables, availability, sample, bodyMax, onClose }) {
    const editing = Boolean(template?.id);
    // Opened with «Ver»: the same form, read-only (a person who cannot manage templates only reads them).
    const readOnly = Boolean(template?.readOnly);
    const form = useForm({ name: template?.name ?? '', category: template?.category ?? '', body: template?.body ?? '' });
    const { data, setData, errors } = form;

    const submit = (event) => {
        event.preventDefault();
        const request = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('campaigns.templates.update', template.id), request);
        else form.post(route('campaigns.templates.store'), request);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{readOnly ? 'Plantilla' : editing ? 'Editar plantilla' : 'Nueva plantilla'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-5 sm:px-8">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="template_name" value="Nombre" />
                            <TextInput id="template_name" className="mt-1" value={data.name} maxLength={120} disabled={readOnly} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="template_category" value="Categoría (opcional)" />
                            <TextInput id="template_category" className="mt-1" value={data.category ?? ''} maxLength={60} disabled={readOnly} onChange={(e) => setData('category', e.target.value)} />
                            <InputError message={errors.category} className="mt-1" />
                        </div>
                    </div>
                    <MessageEditor id="template_body" value={data.body} onChange={(value) => setData('body', value)} error={errors.body} variables={variables} availability={availability} sample={sample} max={bodyMax} disabled={readOnly} />
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        {readOnly ? 'Cerrar' : 'Cancelar'}
                    </SecondaryButton>
                    {!readOnly && (
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {editing ? 'Guardar cambios' : 'Crear plantilla'}
                        </PrimaryButton>
                    )}
                </div>
            </form>
        </Modal>
    );
}
