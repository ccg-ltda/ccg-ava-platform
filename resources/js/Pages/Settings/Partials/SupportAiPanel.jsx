import { useForm } from '@inertiajs/react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import Toggle from '@/Components/Toggle';

/**
 * Soporte con IA (platform administration, Desarrollo_DEV). Turns on the AI that attends CCG's support and picks its workflow from the platform catalog. It is off until a valid
 * configuration is saved; if n8n fails, the conversation goes to a person. Nothing here shows or accepts a secret of n8n.
 */
export default function SupportAiPanel({ supportAi }) {
    const form = useForm({ enabled: supportAi.enabled, workflow: supportAi.workflow ?? supportAi.catalog[0]?.key ?? '' });
    const blocked = supportAi.problem !== null;

    const submit = (event) => {
        event.preventDefault();
        form.put(route('support-ai.update'), { preserveScroll: true });
    };

    return (
        <Card className="space-y-5 p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-lg font-bold text-ink">Soporte con IA</h2>
                    <p className="mt-1 text-sm text-ink-muted">La IA atiende primero las solicitudes de soporte de los clientes y el chat público de CCG. Es de la administración de la plataforma: cada empresa configura sus propios asistentes en Asistentes.</p>
                </div>
                <Badge tone={supportAi.enabled ? 'green' : 'neutral'}>{supportAi.enabled ? 'Activo' : 'Atiende el equipo'}</Badge>
            </div>

            <dl className="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Asistente de soporte</dt><dd className="mt-1 font-medium text-ink">{supportAi.chatbot?.name ?? 'No disponible'}</dd></div>
                <div><dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Workflow asignado</dt><dd className="mt-1 font-medium text-ink">{supportAi.catalog.find((w) => w.key === supportAi.workflow)?.name ?? 'Ninguno'}</dd></div>
                <div><dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">n8n de la plataforma</dt><dd className="mt-1 font-medium text-ink">{supportAi.n8nConfigured ? 'Configurado' : 'Sin configurar'}</dd></div>
            </dl>

            {blocked && <p className="rounded-lg bg-accent-amber/10 px-3 py-2 text-sm text-accent-amber" role="status">{supportAi.problem}</p>}

            {supportAi.catalog.length === 0 && (
                <div className="rounded-lg border border-line bg-canvas/40 p-4 text-sm text-ink-muted">
                    <p className="font-semibold text-ink">¿Por qué no hay workflows?</p>
                    <p className="mt-1">El catálogo de workflows lo registra el equipo técnico en el código (<code>config/n8n.php</code>, clave <code>workflows</code>) y se publica con el despliegue: ninguna persona puede añadir uno desde la web. Está vacío porque todavía no se ha registrado ninguno.</p>
                    <ol className="mt-2 list-decimal space-y-1 pl-5">
                        <li>Crear el workflow en el n8n de la plataforma y anotar su id y la ruta de su webhook.</li>
                        <li>Registrarlo en <code>config/n8n.php</code> (nombre, <code>n8n_workflow_id</code>, <code>webhook_path</code>, versión) y desplegar.</li>
                        <li>Comprobar con <code>php artisan workflows:manage list</code> y volver a esta pantalla para activarlo.</li>
                    </ol>
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Toggle checked={Boolean(form.data.enabled)} onChange={(value) => form.setData('enabled', value)} disabled={!supportAi.canEdit || blocked} label="Que la IA atienda el soporte" description="Apagado, todas las solicitudes las atiende una persona." />
                <InputError message={form.errors.enabled} />

                <div className="max-w-sm">
                    <InputLabel htmlFor="support_ai_workflow" value="Workflow de n8n" />
                    <select
                        id="support_ai_workflow"
                        value={form.data.workflow}
                        onChange={(event) => form.setData('workflow', event.target.value)}
                        disabled={!supportAi.canEdit || blocked || !form.data.enabled}
                        className="mt-1 w-full rounded-md border border-field bg-card px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-60"
                    >
                        {supportAi.catalog.map((workflow) => <option key={workflow.key} value={workflow.key}>{workflow.name}{workflow.version ? ` (v${workflow.version})` : ''}</option>)}
                    </select>
                    <InputError message={form.errors.workflow} className="mt-1" />
                    <p className="mt-1 text-xs text-ink-muted">Los workflows disponibles los registra el equipo de la plataforma junto con el código.</p>
                </div>

                <p className="text-xs text-ink-muted">{supportAi.fallback}</p>

                {supportAi.canEdit && <PrimaryButton type="submit" disabled={form.processing || blocked}>Guardar cambios</PrimaryButton>}
            </form>
        </Card>
    );
}
