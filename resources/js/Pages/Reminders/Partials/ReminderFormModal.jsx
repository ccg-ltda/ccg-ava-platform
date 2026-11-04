import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import DateField from '@/Components/DateField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import TimeField from '@/Components/TimeField';
import useAccess from '@/Hooks/useAccess';
import MessageFields from './MessageFields';
import RecipientPicker, { ineligibleReason } from './RecipientPicker';

const newKey = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `ui-${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * Create / edit a reminder. The channels come from the server with what is really available in this Workspace (an unavailable one is shown with its reason and can not be
 * chosen); the server validates everything again, including that the customer and the related record belong to the Workspace. The date and time are in the Workspace's timezone.
 * Every row is a two-column grid of [label above control] cells so the fields line up; the message and channel are the shared MessageFields.
 */
export default function ReminderFormModal({ reminder, channels, variables, relatedTypes, limits, timezone, onClose }) {
    const editing = Boolean(reminder);
    const { permissions } = useAccess();
    const firstAvailable = channels.find((channel) => channel.available)?.key ?? '';
    const form = useForm({
        idempotency_key: newKey(),
        customer_id: reminder?.customer.id ?? '',
        customer_ids: [],
        name: reminder?.name ?? '',
        purpose: reminder?.purpose ?? '',
        channel: reminder?.channel ?? firstAvailable,
        subject: reminder?.subject ?? '',
        body: reminder?.body ?? '',
        template_name: reminder?.templateName ?? '',
        template_language: reminder?.templateLanguage ?? 'es',
        template_params: reminder?.templateParams ?? [],
        date: reminder?.sendAtLocal?.slice(0, 10) ?? '',
        time: reminder?.sendAtLocal?.slice(11, 16) ?? '',
        related_type: reminder?.relatedType ?? '',
        related_id: reminder?.relatedId ?? '',
    });
    const { data, setData, errors } = form;
    const [selected, setSelected] = useState([]);
    const [previewId, setPreviewId] = useState(null);
    const maxRecipients = limits.maxRecipients ?? 50;
    const previewOf = selected.find((item) => item.id === previewId) ?? selected[0] ?? null;
    const previewValues = previewOf
        ? { nombre: previewOf.firstName ?? '', apellido: previewOf.lastName ?? '', nombre_completo: previewOf.name, telefono: previewOf.phone ?? '', correo: previewOf.email ?? '' }
        : null;
    const blocked = selected.filter((item) => ineligibleReason(item, data.channel));

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        form.transform(({ customer_ids, customer_id, related_type, related_id, ...values }) => ({
            ...values,
            send_at: values.date && values.time ? `${values.date}T${values.time}` : '',
            // Creating: one reminder per chosen customer (ids only; the server resolves them in this Workspace). Editing keeps the one customer and the link it already had.
            ...(editing ? { customer_id, related_type, related_id } : { customer_ids: selected.map((item) => item.id) }),
        }));
        editing ? form.put(route('reminders.update', reminder.id), options) : form.post(route('reminders.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar recordatorio' : 'Nuevo recordatorio'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Un seguimiento comercial a uno o varios clientes: cada uno recibe su propio mensaje. Solo se envía a quien dio su consentimiento, y se vuelve a comprobar en el momento del envío. La fecha y la hora son de {timezone}.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    {editing ? (
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-ink">Cliente</p>
                            <p className="mt-1 text-sm text-ink">{reminder.customer.name}</p>
                            <InputError message={errors.customer_id} className="mt-1" />
                            {reminder.relatedType && <p className="mt-1 text-xs text-ink-muted">Vinculado a {relatedTypes[reminder.relatedType]}: si se cancela antes del envío, el recordatorio no sale.</p>}
                        </div>
                    ) : (
                        <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                            <RecipientPicker selected={selected} onChange={setSelected} channel={data.channel} canCreate={permissions.includes('create-customers')} limit={maxRecipients} error={errors.customer_ids ?? errors.customer_id} />
                        </div>
                    )}
                    {blocked.length > 0 && (
                        <p role="status" className="-mt-2 text-xs text-warning">
                            {blocked.length === 1 ? '1 cliente no podrá recibirlo' : `${blocked.length} clientes no podrán recibirlo`} por este canal ({blocked.map((item) => `${item.name}: ${ineligibleReason(item, data.channel)}`).join('; ')}). Se omitirán y el resto sigue; el resultado se muestra al programar.
                        </p>
                    )}

                    <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                        <div className="min-w-0">
                            <InputLabel htmlFor="rem_name" value="Nombre" />
                            <TextInput id="rem_name" className="mt-1" value={data.name} onChange={(event) => setData('name', event.target.value)} invalid={Boolean(errors.name)} maxLength={limits.name} autoComplete="off" />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div className="min-w-0">
                            <InputLabel htmlFor="rem_purpose" value="Finalidad (opcional)" />
                            <TextInput id="rem_purpose" className="mt-1" value={data.purpose} onChange={(event) => setData('purpose', event.target.value)} maxLength={limits.purpose} placeholder="Invitar a reservar de nuevo" autoComplete="off" />
                            <InputError message={errors.purpose} className="mt-1" />
                        </div>
                        <DateField id="rem_date" label="Fecha de envío" clearable={false} value={data.date} onChange={(value) => setData('date', value)} error={errors.send_at} />
                        <TimeField id="rem_time" label="Hora de envío" value={data.time} onChange={(value) => setData('time', value)} />
                    </div>

                    {selected.length > 1 && (
                        <div className="max-w-sm min-w-0">
                            <InputLabel htmlFor="rem_preview_as" value="Vista previa para" />
                            <Select id="rem_preview_as" className="mt-1" value={previewOf?.id ?? ''} onChange={(id) => setPreviewId(Number(id))} options={selected.map((item) => ({ value: item.id, label: item.name }))} />
                        </div>
                    )}
                    <MessageFields data={data} setData={setData} errors={errors} channels={channels} variables={variables} limits={limits} idPrefix="rem" previewValues={previewValues} />

                </div>

                <div className="flex flex-wrap justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cancelar</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>{editing ? 'Guardar cambios' : selected.length > 1 ? `Programar ${selected.length} recordatorios` : 'Programar recordatorio'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
