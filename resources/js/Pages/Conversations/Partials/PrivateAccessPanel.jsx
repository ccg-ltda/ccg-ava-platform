import { router, useForm } from '@inertiajs/react';
import { LockKeyhole, ShieldCheck } from 'lucide-react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import useFormatDateTime from '@/Hooks/useFormatDateTime';

/**
 * What a platform administrator sees instead of another Workspace's conversations: the content is private and is opened only with a
 * written reason, for a few minutes, one Workspace at a time. Every read is recorded in that Workspace's audit log.
 */
const LABELS = { ai: 'IA atendiendo', pending: 'Pendientes de agente', human: 'En atención', resolved: 'Resueltas' };

export function PrivateAccessPanel({ access, workspace, counts }) {
    const form = useForm({ workspace_id: workspace.id, data_type: 'conversations', reason: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(route('support-access.store'), { preserveScroll: true, onSuccess: () => form.reset('reason') });
    };

    return (
        <Card className="mx-auto max-w-2xl space-y-4 p-6">
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-primary-soft text-primary">
                    <LockKeyhole className="size-5" aria-hidden="true" />
                </span>
                <div>
                    <h2 className="text-base font-bold text-ink">Contenido privado de {workspace.name}</h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        Los mensajes y contactos de un Workspace cliente no se muestran desde otro Workspace, ni siquiera a un superusuario. Abajo puedes ver cuántas conversaciones hay en cada estado sin acceder a su contenido.
                    </p>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Conversaciones por estado">
                {Object.entries(LABELS).map(([key, label]) => (
                    <div key={key} className="rounded-lg border border-line bg-canvas/60 p-3 text-center">
                        <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</dt>
                        <dd className="text-xl font-extrabold text-ink">{counts[key] ?? 0}</dd>
                    </div>
                ))}
            </dl>

            {access.canRequest ? (
                <form onSubmit={submit} noValidate className="space-y-3">
                    <div>
                        <InputLabel htmlFor="support_reason" value={`Motivo del acceso (mínimo ${access.reasonMin} caracteres)`} />
                        <Textarea id="support_reason" className="mt-1" value={form.data.reason} maxLength={500} onChange={(e) => form.setData('reason', e.target.value)} invalid={Boolean(form.errors.reason)} placeholder="Por ejemplo: el cliente reportó que el asistente no responde; ticket #123" />
                        <InputError message={form.errors.reason} className="mt-1" />
                    </div>
                    <Alert tone="info">
                        El acceso dura {access.minutes} minutos, es solo de lectura, muestra los contactos enmascarados y queda registrado en la auditoría de este Workspace con tu nombre, el motivo y la fecha.
                    </Alert>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Abrir acceso temporal
                    </PrimaryButton>
                </form>
            ) : (
                <Alert tone="info">Tu rol no tiene permiso para abrir un acceso a datos privados. Pídelo a quien administra los permisos de la plataforma.</Alert>
            )}
        </Card>
    );
}

/** Shown while a support access is open: until when, why, and how to close it early. */
export function ActiveAccessNotice({ access }) {
    const formatDateTime = useFormatDateTime();

    return (
        <Alert tone="warning">
            <p className="flex items-center gap-2 font-semibold">
                <ShieldCheck className="size-4" aria-hidden="true" />
                Acceso temporal a datos privados hasta las {formatDateTime(access.grant.expiresAt)}
            </p>
            <p className="mt-1">Motivo registrado: {access.grant.reason}. Los contactos aparecen enmascarados y cada consulta queda en la auditoría.</p>
            <SecondaryButton type="button" className="mt-2" onClick={() => router.delete(route('support-access.destroy', access.grant.id), { preserveScroll: true })}>
                Cerrar acceso ahora
            </SecondaryButton>
        </Alert>
    );
}
