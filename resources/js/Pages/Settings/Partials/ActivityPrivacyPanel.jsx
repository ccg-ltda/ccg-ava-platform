import { useForm } from '@inertiajs/react';
import Card from '@/Components/Card';
import PrimaryButton from '@/Components/PrimaryButton';
import Toggle from '@/Components/Toggle';

/**
 * Asistentes > Privacidad de la actividad técnica. Saved on its own. Credentials are never stored whatever this says; the switch decides whether the request and response
 * contents of the assistant's API calls are kept next to their technical data. The retention days come from the server.
 */
export default function ActivityPrivacyPanel({ activityPrivacy, canEdit }) {
    const form = useForm({ store_bodies: activityPrivacy.storeBodies });

    const submit = (event) => {
        event.preventDefault();
        form.put(route('settings.activity-privacy'), { preserveScroll: true });
    };

    return (
        <Card className="space-y-5 p-4 sm:p-6">
            <div>
                <h2 className="text-lg font-bold text-ink">Privacidad de la actividad técnica</h2>
                <p className="mt-1 text-sm text-ink-muted">Qué se guarda de las llamadas a APIs que hace el asistente en tus conversaciones.</p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                <Toggle
                    checked={Boolean(form.data.store_bodies)}
                    onChange={(value) => form.setData('store_bodies', value)}
                    disabled={!canEdit}
                    label="Guardar el contenido de las solicitudes y respuestas"
                    description="Si lo desactivas, solo se guardan datos técnicos (herramienta, método, endpoint, estado, duración). Los mensajes de las conversaciones no cambian."
                />

                <div className="rounded-lg border border-line bg-canvas/40 p-4 text-sm text-ink-muted">
                    <p className="font-semibold text-ink">Qué se conserva y por cuánto tiempo</p>
                    <ul className="mt-1 list-disc space-y-1 pl-5">
                        <li>Credenciales, contraseñas, tokens, cookies y llaves: nunca se guardan.</li>
                        <li>Correos y teléfonos dentro del contenido se guardan enmascarados (por ejemplo a***@e***.com y ••••1234).</li>
                        <li>Contenido de solicitudes y respuestas: {activityPrivacy.bodyDays} días, y solo lo ven las personas con el permiso «Ver el contenido completo de la actividad técnica».</li>
                        <li>Datos técnicos de cada paso: {activityPrivacy.metadataDays} días; después se eliminan.</li>
                    </ul>
                </div>

                {canEdit && <PrimaryButton type="submit" disabled={form.processing}>Guardar cambios</PrimaryButton>}
            </form>
        </Card>
    );
}
