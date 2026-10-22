import { Head, Link, router, useForm } from '@inertiajs/react';
import DateField from '@/Components/DateField';
import { ArrowLeft, Ban, BellRing, Pencil, Plus, RotateCw } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import AppLayout from '@/Layouts/AppLayout';

const TONES = { scheduled: 'blue', queued: 'blue', sending: 'blue', sent: 'green', failed: 'red', uncertain: 'amber', cancelled: 'neutral', skipped: 'amber' };

const when = (iso, timezone) => new Date(iso).toLocaleString('es', { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' });

/** Create or edit ONE reminder. The recipient is not a field: it is always the person who creates it (the server decides it). */
function ReminderDialog({ reminder, limits, timezone, onClose }) {
    const editing = Boolean(reminder?.id);
    const form = useForm({ subject: reminder?.subject ?? '', body: reminder?.body ?? '', scheduled_at: reminder?.scheduledLocal ?? '' });
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('catalog.reminders.update', reminder.id), options);
        else form.post(route('catalog.reminders.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} noValidate className="p-6">
                <h2 className="text-lg font-bold text-ink">{editing ? 'Editar recordatorio' : 'Nuevo recordatorio'}</h2>
                <p className="mt-1 text-sm text-ink-muted">Llega por correo a tu cuenta a la hora indicada ({timezone}). No se envía a nadie más.</p>

                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel htmlFor="rem_subject" value="Asunto" />
                        <TextInput id="rem_subject" className="mt-1" maxLength={limits.subject} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} invalid={Boolean(form.errors.subject)} placeholder="Por ejemplo: Alistar el pedido de la tarde" />
                        <InputError message={form.errors.subject} className="mt-1" />
                    </div>
                    <DateField id="rem_when" label="Fecha y hora de envío" type="datetime-local" value={form.data.scheduled_at} onChange={(value) => form.setData('scheduled_at', value)} error={form.errors.scheduled_at} />
                    <div>
                        <InputLabel htmlFor="rem_body" value="Mensaje" />
                        <Textarea id="rem_body" rows={6} className="mt-1" maxLength={limits.body} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} invalid={Boolean(form.errors.body)} />
                        <div className="mt-1 flex justify-between gap-3 text-xs text-ink-muted">
                            <InputError message={form.errors.body} />
                            <span className="ml-auto tabular-nums">{form.data.body.length}/{limits.body}</span>
                        </div>
                    </div>
                    <InputError message={form.errors.status} />
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancelar</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Programar'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/** Recordatorios del catálogo: emails scheduled for the person who writes them. The list shows every state honestly (nothing is "sent" unless the mailer accepted it). */
export default function Reminders({ schemaPending, timezone, mailReady, me, canManage, limits, reminders }) {
    const confirm = useConfirm();
    const [dialog, setDialog] = useState(null); // {} = new, a reminder = edit

    const cancel = async (reminder) => {
        const confirmed = await confirm({
            title: 'Cancelar el recordatorio',
            description: `«${reminder.subject}» no se enviará. Queda en el historial como cancelado.`,
            confirmLabel: 'Cancelar recordatorio',
        });

        if (confirmed) router.post(route('catalog.reminders.cancel', reminder.id), {}, { preserveScroll: true });
    };

    const retry = async (reminder) => {
        const confirmed = await confirm({
            title: 'Reintentar el envío',
            description: reminder.status === 'uncertain'
                ? 'Se interrumpió mientras se enviaba y pudo haber llegado ya. Si lo reintentas, podrías recibirlo dos veces.'
                : 'Se volverá a intentar enviar ahora a tu correo.',
            confirmLabel: 'Reintentar',
        });

        if (confirmed) router.post(route('catalog.reminders.retry', reminder.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Recordatorios del catálogo" />

            <Link href={route('catalog.index')} className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-accent-blue hover:underline">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Catálogo
            </Link>

            <PageHeader title="Recordatorios" description="Escribe un aviso para ti con fecha y hora, por ejemplo «alistar el pedido de las 2:00 p. m.». Llega por correo a tu cuenta; no se envía a otras personas.">
                {canManage && !schemaPending && (
                    <PrimaryButton type="button" onClick={() => setDialog({})} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo recordatorio
                    </PrimaryButton>
                )}
            </PageHeader>

            {schemaPending && <Alert tone="warning">Los recordatorios todavía no están disponibles en esta base de datos (falta aplicar la migración pendiente).</Alert>}
            {!schemaPending && !mailReady && <Alert tone="warning" className="mt-4">El correo saliente no está configurado en este entorno: los recordatorios quedarán como «Falló» en lugar de enviarse.</Alert>}

            <Card className="mt-6 overflow-hidden">
                {reminders.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={BellRing} title="Todavía no hay recordatorios" description="Programa el primero: te llegará por correo a la hora que elijas." />
                    </div>
                ) : (
                    <ul className="divide-y divide-line">
                        {reminders.map((reminder) => {
                            const open = ['scheduled', 'failed'].includes(reminder.status);
                            const cancellable = ['scheduled', 'queued', 'failed', 'uncertain'].includes(reminder.status);

                            return (
                                <li key={reminder.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-4 sm:px-6">
                                    <div className="min-w-0 flex-1">
                                        <p className="flex flex-wrap items-center gap-2 text-sm font-bold text-ink">
                                            <span className="break-words">{reminder.subject}</span>
                                            <Badge tone={TONES[reminder.status]}>{reminder.statusLabel}</Badge>
                                        </p>
                                        <p className="mt-1 text-sm text-ink-muted">
                                            {reminder.status === 'sent' && reminder.sentAt ? `Enviado el ${when(reminder.sentAt, timezone)}` : `Programado para el ${when(reminder.scheduledAt, timezone)}`}
                                            {' · '}
                                            {reminder.mine ? 'Tuyo' : `De ${reminder.creator ?? 'otra persona'}`}
                                            {reminder.attempts > 0 && ` · ${reminder.attempts} intento${reminder.attempts === 1 ? '' : 's'}`}
                                        </p>
                                        <p className="mt-1 line-clamp-2 text-sm whitespace-pre-line text-ink">{reminder.body}</p>
                                        {reminder.reason && <p className="mt-1 text-xs text-ink-muted">{reminder.reason}</p>}
                                        {reminder.status === 'scheduled' && reminder.nextAttemptAt && <p className="mt-1 text-xs text-ink-muted">Próximo intento: {when(reminder.nextAttemptAt, timezone)}</p>}
                                        {reminder.status === 'cancelled' && <p className="mt-1 text-xs text-ink-muted">Cancelado{reminder.cancelledBy ? ` por ${reminder.cancelledBy}` : ''}{reminder.cancelledAt ? ` el ${when(reminder.cancelledAt, timezone)}` : ''}.</p>}
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-1">
                                            {reminder.mine && open && <IconButton icon={Pencil} label="Editar" context={reminder.subject} onClick={() => setDialog(reminder)} />}
                                            {reminder.mine && ['failed', 'uncertain'].includes(reminder.status) && <IconButton icon={RotateCw} label="Reintentar" context={reminder.subject} onClick={() => retry(reminder)} />}
                                            {cancellable && <IconButton icon={Ban} label="Cancelar" context={reminder.subject} tone="danger" onClick={() => cancel(reminder)} />}
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>

            {dialog && <ReminderDialog reminder={dialog.id ? dialog : null} limits={limits} timezone={timezone} onClose={() => setDialog(null)} />}
        </>
    );
}

Reminders.layout = (page) => <AppLayout>{page}</AppLayout>;
