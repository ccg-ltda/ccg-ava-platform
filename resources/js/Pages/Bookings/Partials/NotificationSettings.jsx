import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import MessageFields from './MessageFields';

const KINDS = [
    { key: 'received', title: 'Recepción de la reserva', description: 'Se envía cuando una reserva queda «Por confirmar»: dice que se recibió, no que está confirmada.' },
    { key: 'confirmed', title: 'Confirmación de la cita', description: 'Se envía cuando la cita queda confirmada (al reservar o cuando el equipo la acepta).' },
    { key: 'reminder', title: 'Recordatorio', description: 'Se envía antes de la cita, según las anticipaciones de arriba.' },
];

/** "1 día antes", "2 h antes", "45 min antes" for a number of minutes. */
const humanize = (minutes) => {
    if (!minutes) return '';
    if (minutes % 1440 === 0) return `${minutes / 1440} ${minutes === 1440 ? 'día' : 'días'} antes`;
    if (minutes % 60 === 0) return `${minutes / 60} h antes`;

    return `${minutes} min antes`;
};

const initialMessages = (messages) =>
    Object.fromEntries(
        KINDS.map(({ key }) => {
            const entry = messages?.[key] ?? {};

            return [key, { text: entry.text ?? '', template: { name: entry.template?.name ?? '', language: entry.template?.language ?? '', params: [...(entry.template?.params ?? [])] } }];
        }),
    );

/**
 * Confirmations and reminders of the Workspace: one shared configuration for any business that books (a clinic, a salon...). It never looks
 * available when it is not: without a usable WhatsApp account, a plan with Integraciones or Ava's own agenda the switches stay off and the screen says why.
 */
export default function NotificationSettings({ notifications, canManage }) {
    const form = useForm({ ...notifications.values, reminder_offsets: notifications.values.reminder_offsets ?? [], messages: initialMessages(notifications.values.messages) });
    const { data, setData, errors } = form;
    const locked = !canManage || !notifications.ready;
    const cannotTurnOn = (current) => locked || (!notifications.canSend && !current);

    const submit = (event) => {
        event.preventDefault();
        form.transform((payload) => ({
            ...payload,
            notify_integration_id: payload.notify_integration_id || null,
            messages: Object.fromEntries(
                KINDS.map(({ key }) => {
                    const entry = payload.messages[key];

                    return [key, { text: entry.text, template: { name: entry.template.name, language: entry.template.language, params: entry.template.params } }];
                }),
            ),
        }));
        form.put(route('bookings.settings.notifications'), { preserveScroll: true });
    };

    const setOffset = (index, value) => setData('reminder_offsets', data.reminder_offsets.map((item, i) => (i === index ? (value === '' ? '' : Number(value)) : item)));
    const setMessage = (kind, message) => setData('messages', { ...data.messages, [kind]: message });
    const offsetError = errors.reminder_offsets ?? Object.entries(errors).find(([key]) => key.startsWith('reminder_offsets.'))?.[1];

    return (
        <form onSubmit={submit} noValidate>
            <Card className="space-y-6 p-5 sm:p-6">
                <div>
                    <h2 className="text-base font-bold text-ink">Confirmaciones y recordatorios</h2>
                    <p className="mt-0.5 text-sm text-ink-muted">Avisos por WhatsApp sobre las citas de este Workspace. Cada negocio decide qué envía y cuándo; nada se envía mientras no lo actives.</p>
                </div>

                {!notifications.ready && <Alert tone="warning">Falta aplicar la última migración de la base de datos en este entorno: estas opciones no están disponibles todavía.</Alert>}
                {notifications.ready && notifications.problem && (
                    <Alert tone="warning">
                        {notifications.problem}{' '}
                        <Link href={route('integrations.index')} className="font-semibold underline">Ir a Integraciones</Link>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Toggle checked={data.notify_received} onChange={(value) => setData('notify_received', value)} disabled={cannotTurnOn(notifications.values.notify_received)} label="Avisar que recibimos la reserva" description="Solo para reservas que quedan «Por confirmar»." />
                    <Toggle checked={data.notify_confirmed} onChange={(value) => setData('notify_confirmed', value)} disabled={cannotTurnOn(notifications.values.notify_confirmed)} label="Avisar que la cita está confirmada" description="Cuando la cita queda confirmada." />
                    <Toggle checked={data.reminders_enabled} onChange={(value) => setData('reminders_enabled', value)} disabled={cannotTurnOn(notifications.values.reminders_enabled)} label="Enviar recordatorios" description="Antes de las citas confirmadas." />
                </div>
                <InputError message={errors.reminders_enabled} />

                {notifications.accounts.length > 1 && (
                    <div className="max-w-sm">
                        <InputLabel htmlFor="notify_account" value="Cuenta de WhatsApp para los avisos" />
                        <Select id="notify_account" className="mt-1" value={data.notify_integration_id ?? ''} onChange={(value) => setData('notify_integration_id', value)} disabled={locked} placeholder="Elige una cuenta" options={notifications.accounts.map((account) => ({ value: account.id, label: account.name }))} invalid={Boolean(errors.notify_integration_id)} />
                        <InputError message={errors.notify_integration_id} className="mt-1" />
                    </div>
                )}

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium text-ink">Recordatorios: cuánto antes de la cita</legend>
                    <p className="text-xs text-ink-muted">Hasta {notifications.limits.max_count} recordatorios, entre {notifications.limits.min} minutos y {notifications.limits.max / 1440} días antes. Se usa la hora de la cita en la zona horaria del Workspace.</p>
                    <div className="space-y-2">
                        {data.reminder_offsets.map((offset, index) => (
                            <div key={index} className="flex items-center gap-2">
                                <TextInput type="number" min={notifications.limits.min} max={notifications.limits.max} className="w-32" aria-label={`Recordatorio ${index + 1}: minutos antes`} value={offset} onChange={(e) => setOffset(index, e.target.value)} disabled={locked} />
                                <span className="text-sm text-ink-muted">min · {humanize(Number(offset))}</span>
                                {!locked && <IconButton icon={Trash2} label="Quitar recordatorio" context={`${index + 1}`} tone="danger" onClick={() => setData('reminder_offsets', data.reminder_offsets.filter((_, i) => i !== index))} />}
                            </div>
                        ))}
                    </div>
                    {!locked && data.reminder_offsets.length < notifications.limits.max_count && (
                        <SecondaryButton type="button" className="gap-2" onClick={() => setData('reminder_offsets', [...data.reminder_offsets, data.reminder_offsets.length === 0 ? 1440 : 120])}>
                            <Plus className="size-4" aria-hidden="true" />
                            Añadir recordatorio
                        </SecondaryButton>
                    )}
                    <InputError message={offsetError} />
                </fieldset>

                <div className="space-y-4">
                    <div>
                        <h3 className="text-sm font-bold text-ink">Qué dicen los mensajes</h3>
                        <p className="mt-0.5 text-xs text-ink-muted">
                            WhatsApp solo permite un texto libre si la persona escribió al negocio en las últimas {notifications.limits.window_hours} horas; fuera de ese plazo solo se puede enviar una plantilla ya aprobada por Meta. Ava no puede comprobar con Meta si la plantilla está aprobada: si Meta la rechaza, el aviso queda «Falló» y el motivo se ve en la Agenda.
                        </p>
                    </div>
                    {KINDS.map(({ key, title, description }) => (
                        <section key={key} aria-labelledby={`msg_title_${key}`} className="space-y-4 rounded-xl border border-line p-4 sm:p-5">
                            <div>
                                <h4 id={`msg_title_${key}`} className="text-sm font-semibold text-ink">{title}</h4>
                                <p className="text-xs text-ink-muted">{description}</p>
                            </div>
                            <MessageFields
                                kind={key}
                                message={data.messages[key]}
                                onChange={(message) => setMessage(key, message)}
                                variables={notifications.variables}
                                fallback={notifications.defaults[key]}
                                limits={notifications.limits}
                                errors={errors}
                                disabled={locked}
                            />
                        </section>
                    ))}
                </div>

                {canManage && notifications.ready && (
                    <div className="flex justify-end">
                        <PrimaryButton type="submit" disabled={form.processing}>
                            Guardar avisos
                        </PrimaryButton>
                    </div>
                )}
            </Card>
        </form>
    );
}
