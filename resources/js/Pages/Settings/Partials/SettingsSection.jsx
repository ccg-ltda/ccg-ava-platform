import { Pencil } from 'lucide-react';
import Card from '@/Components/Card';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/** One saved value of a summary: a small label over the value (or a dash when nothing was set). */
export function Fact({ label, children, className = '' }) {
    const empty = children === null || children === undefined || children === '';

    return (
        <div className={`min-w-0 rounded-lg border border-line bg-canvas/50 px-4 py-3 ${className}`}>
            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</dt>
            <dd className="mt-1 text-sm font-medium break-words text-ink">{empty ? <span className="font-normal text-ink-muted">No configurado</span> : children}</dd>
        </div>
    );
}

/**
 * One tab of Configuraciones in two states. Summary: what is saved, in a compact layout, with «Editar» (only for who may edit). Editing: the form of
 * this section only, with «Guardar cambios» (validates and saves, then the summary is back with the saved values) and «Cancelar» (drops the pending
 * changes). Only one section is edited at a time, so the one form of the page always holds either the saved values or the section being edited.
 */
export default function SettingsSection({ title, description, form, onSave, editing, onEdit, onCancel, canEdit, summary, children }) {
    if (!editing) {
        return (
            <Card className="overflow-hidden">
                <div className="flex flex-wrap items-start justify-between gap-3 p-4 sm:p-6">
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-ink">{title}</h2>
                        <p className="mt-1 text-sm text-ink-muted">{description}</p>
                    </div>
                    {canEdit && (
                        <SecondaryButton type="button" onClick={onEdit} className="gap-2">
                            <Pencil className="size-4" aria-hidden="true" />
                            Editar
                        </SecondaryButton>
                    )}
                </div>
                <div className="border-t border-line p-4 sm:p-6">{summary}</div>
            </Card>
        );
    }

    return (
        <Card className="overflow-hidden">
            <form onSubmit={onSave} noValidate aria-label={`Editar ${title}`}>
                <div className="p-4 sm:p-6">
                    <h2 className="text-lg font-bold text-ink">Editar {title.toLowerCase()}</h2>
                    <p className="mt-1 text-sm text-ink-muted">{description}</p>

                    <div className="mt-6 max-w-2xl space-y-6">{children}</div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-canvas/60 px-4 py-3 sm:px-6">
                    <p className="text-sm text-ink-muted" aria-live="polite">
                        {form.isDirty ? 'Tienes cambios sin guardar.' : 'No hay cambios pendientes.'}
                    </p>
                    <div className="flex gap-3">
                        <SecondaryButton type="button" onClick={onCancel} disabled={form.processing}>
                            Cancelar
                        </SecondaryButton>
                        <PrimaryButton type="submit" disabled={form.processing || !form.isDirty}>
                            {form.processing ? 'Guardando...' : 'Guardar cambios'}
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </Card>
    );
}
