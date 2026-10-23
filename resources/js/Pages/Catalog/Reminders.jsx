import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Ban, BellRing, Pencil, Plus, RotateCw } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import DateField from '@/Components/DateField';
import EmptyState from '@/Components/EmptyState';
import EntityPicker from '@/Components/EntityPicker';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TimeField from '@/Components/TimeField';
import TextInput from '@/Components/TextInput';
import AppLayout from '@/Layouts/AppLayout';

const TONES = { scheduled: 'blue', queued: 'blue', sending: 'blue', sent: 'green', failed: 'red', uncertain: 'amber', cancelled: 'neutral', skipped: 'amber' };

const when = (iso, timezone) => new Date(iso).toLocaleString('es', { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' });

/**
 * Create or edit ONE reminder. The recipients are chosen here from the members of the Workspace the server offered (enabled, with an address and access to the
 * reminders); the server validates every id again and re-checks each person when it sends. The date and the time are two controls and travel as one local value.
 */
function ReminderDialog({ reminder, limits, timezone, me, recipientOptions, onClose }) {
    const editing = Boolean(reminder?.id);
    const [date, time] = (reminder?.scheduledLocal ?? '').split('T');
    const defaults = recipientOptions.some((person) => person.id === me) ? [me] : [];
    const form = useForm({ subject: reminder?.subject ?? '', body: reminder?.body ?? '', date: date ?? '', time: time ?? '', recipient_ids: reminder ? reminder.recipientIds.filter((id) => recipientOptions.some((person) => person.id === id)) : defaults });
    const people = recipientOptions.map((person) => ({ value: person.id, label: person.name, description: person.email }));
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        form.transform(({ date: day, time: hour, ...values }) => ({ ...values, scheduled_at: day && hour ? `${day}T${hour}` : '' }));

        if (editing) form.put(route('catalog.reminders.update', reminder.id), options);
        else form.post(route('catalog.reminders.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} noValidate className="p-6">
                <h2 className="text-lg font-bold text-ink">{editing ? 'Editar recordatorio' : 'Nuevo recordatorio'}</h2>
                <p className="mt-1 text-sm text-ink-muted">Se envía por correo, a la hora indicada ({timezone}), solo a las personas que elijas abajo. Nadie más lo recibe.</p>

                <div className="mt-4 space-y-4">
                    <div>
                        <InputLabel htmlFor="rem_subject" value="Asunto" />
                        <TextInput id="rem_subject" className="mt-1" maxLength={limits.subject} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} invalid={Boolean(form.errors.subject)} placeholder="Por ejemplo: Alistar el pedido de la tarde" />
                        <InputError message={form.errors.subject} className="mt-1" />
                    </div>
                    <div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <DateField id="rem_date" label="Fecha de envío" clearable={false} value={form.data.date} onChange={(value) => form.setData('date', value)} error={form.errors.scheduled_at} />
                            <TimeField id="rem_time" label="Hora de envío" value={form.data.time} onChange={(value) => form.setData('time', value)} />
                        </div>
                        <p className="mt-1 text-xs text-ink-muted">Hora de {timezone}. Tiene que ser futura.</p>
                    </div>
                    <div>
                        <InputLabel htmlFor="rem_recipients" value="Quién lo recibe" />
                        <p className="mt-0.5 mb-2 text-xs text-ink-muted">Personas de este Workspace con la cuenta activa y acceso a los recordatorios. Cada una recibe el correo en su dirección.</p>
                        <EntityPicker id="rem_recipients" options={people} value={form.data.recipient_ids} onChange={(ids) => form.setData('recipient_ids', ids)} placeholder="Buscar persona por nombre" noneLabel="Nadie elegido todavía" invalid={Boolean(form.errors.recipient_ids)} error={form.errors.recipient_ids} />
                    </div>
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
export default function Reminders({ schemaPending, timezone, mailReady, me, canManage, limits, reminders, recipientOptions = [] }) {
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
                : 'Se volverá a intentar enviar ahora. Quien ya lo recibió no lo recibe otra vez.',
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

            <PageHeader title="Recordatorios" description="Escribe un aviso con fecha y hora, por ejemplo «alistar el pedido de las 2:00 p. m.», y elige quién lo recibe por correo entre las personas de este Workspace. Nadie fuera de tu lista lo recibe.">
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
                        <EmptyState icon={BellRing} title="Todavía no hay recordatorios" description="Programa el primero: llegará por correo a las personas que elijas, a la hora que indiques." />
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
                                            {reminder.mine ? 'Creado por ti' : `Creado por ${reminder.creator ?? 'otra persona'}`}
                                            {reminder.attempts > 0 && ` · ${reminder.attempts} intento${reminder.attempts === 1 ? '' : 's'}`}
                                        </p>
                                        <p className="mt-1 line-clamp-2 text-sm whitespace-pre-line text-ink">{reminder.body}</p>
                                        <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-muted">
                                            <span>Para:</span>
                                            {reminder.recipients.map((person) => (
                                                <span key={person.id} title={person.problem ?? person.email} className={`inline-flex items-center rounded-full px-2 py-0.5 ${person.problem ? 'bg-danger/10 text-danger' : person.delivered ? 'bg-accent-green/15 text-accent-green' : 'bg-canvas text-ink'}`}>
                                                    {person.name}{person.delivered ? ' · recibido' : ''}
                                                </span>
                                            ))}
                                        </p>
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

            {dialog && <ReminderDialog reminder={dialog.id ? dialog : null} limits={limits} timezone={timezone} me={me} recipientOptions={recipientOptions} onClose={() => setDialog(null)} />}
        </>
    );
}

Reminders.layout = (page) => <AppLayout>{page}</AppLayout>;
