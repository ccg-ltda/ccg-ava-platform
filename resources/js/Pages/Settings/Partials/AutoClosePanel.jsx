import { useForm } from '@inertiajs/react';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';

/**
 * Conversaciones > Cierre automático. Saved on its own (it does not go through the general form of the page): the server validates the minutes and applies the setting to this
 * Workspace only. «Sin configurar» keeps the previous behavior until the first save.
 */
export default function AutoClosePanel({ autoClose, canEdit }) {
    const form = useForm({ enabled: autoClose.enabled, minutes: autoClose.minutes });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('settings.conversations'), { preserveScroll: true });
    };

    return (
        <Card className="space-y-5 p-4 sm:p-6">
            <div>
                <h2 className="text-lg font-bold text-ink">Cierre automático</h2>
                <p className="mt-1 text-sm text-ink-muted">Cierra las conversaciones en las que nadie escribe durante un tiempo.</p>
                {!autoClose.configured && <p className="mt-2 text-xs text-ink-muted">Aún no la has configurado: se mantiene el comportamiento anterior (las solicitudes de soporte se cierran a los 4 minutos; el resto de conversaciones no se cierran solas).</p>}
            </div>

            <form onSubmit={submit} className="space-y-5">
                <Toggle checked={Boolean(form.data.enabled)} onChange={(value) => form.setData('enabled', value)} disabled={!canEdit} label="Cerrar conversaciones por inactividad" description="Si lo desactivas, ninguna conversación de este Workspace se cierra sola." />

                <div className="max-w-xs">
                    <InputLabel htmlFor="autoclose_minutes" value="Minutos de inactividad" />
                    <TextInput
                        id="autoclose_minutes"
                        type="number"
                        inputMode="numeric"
                        min={autoClose.min}
                        max={autoClose.max}
                        className="mt-1"
                        value={form.data.minutes}
                        onChange={(event) => form.setData('minutes', event.target.value)}
                        disabled={!canEdit || !form.data.enabled}
                        invalid={Boolean(form.errors.minutes)}
                    />
                    <InputError message={form.errors.minutes} className="mt-1" />
                </div>

                <div className="rounded-lg border border-line bg-canvas/40 p-4 text-sm text-ink-muted">
                    <p className="font-semibold text-ink">Cómo se calcula el tiempo</p>
                    <ul className="mt-1 list-disc space-y-1 pl-5">
                        <li>Se cuenta desde el último mensaje del cliente, de la IA o de un agente. Un «sigo trabajando en tu solicitud» de un agente reinicia el tiempo.</li>
                        <li>Las notificaciones, los cambios de estado, la auditoría y las lecturas de mensajes no reinician el tiempo.</li>
                        <li>Si la IA está procesando una respuesta dentro de su plazo, la conversación no se cierra; un proceso bloqueado no la mantiene abierta.</li>
                        <li>Al cerrar se conservan los mensajes y los archivos; no se envía ningún mensaje al cliente. Si el cliente vuelve a escribir, la conversación se reabre. Entre 1 y 1440 minutos.</li>
                    </ul>
                </div>

                {canEdit && <PrimaryButton type="submit" disabled={form.processing}>Guardar cambios</PrimaryButton>}
            </form>
        </Card>
    );
}
