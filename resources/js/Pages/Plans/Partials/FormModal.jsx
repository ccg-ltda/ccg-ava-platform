import Modal from '@/Components/Modal';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';

/** Name + description fields shared by the forms of the catalog (a module, a solution). */
export function NameDescriptionFields({ form, prefix, nameLabel }) {
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

/** The modal around a small catalog form: title, fields, Cancel / submit. */
export default function FormModal({ show, title, form, onClose, onSubmit, submitLabel, children }) {
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
