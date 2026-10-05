import Card from '@/Components/Card';
import PrimaryButton from '@/Components/PrimaryButton';

/** One tab of Configuraciones: title, short description, fields and the shared save button. */
export default function SettingsSection({ title, description, form, onSave, children }) {
    return (
        <Card className="overflow-hidden">
            <form onSubmit={onSave} noValidate>
                <div className="p-4 sm:p-6">
                    <h2 className="text-lg font-bold text-ink">{title}</h2>
                    <p className="mt-1 text-sm text-ink-muted">{description}</p>

                    <div className="mt-6 max-w-2xl space-y-6">{children}</div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-canvas/60 px-4 py-3 sm:px-6">
                    <p className="text-sm text-ink-muted" aria-live="polite">
                        {form.isDirty ? 'Tienes cambios sin guardar.' : 'No hay cambios pendientes.'}
                    </p>
                    <PrimaryButton type="submit" disabled={form.processing || !form.isDirty}>
                        {form.processing ? 'Guardando...' : 'Guardar cambios'}
                    </PrimaryButton>
                </div>
            </form>
        </Card>
    );
}
