import axios from 'axios';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DateField from '@/Components/DateField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import TimeField from '@/Components/TimeField';
import MessageFields from './MessageFields';

const newKey = () => (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `ui-${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * Create / edit a reminder. The channels come from the server with what is really available in this Workspace (an unavailable one is shown with its reason and can not be
 * chosen); the server validates everything again, including that the customer and the related record belong to the Workspace. The date and time are in the Workspace's timezone.
 * Every row is a two-column grid of [label above control] cells so the fields line up; the message and channel are the shared MessageFields.
 */
export default function ReminderFormModal({ reminder, channels, variables, relatedTypes, limits, timezone, onClose }) {
    const editing = Boolean(reminder);
    const firstAvailable = channels.find((channel) => channel.available)?.key ?? '';
    const form = useForm({
        idempotency_key: newKey(),
        customer_id: reminder?.customer.id ?? '',
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
    const [query, setQuery] = useState('');
    const [found, setFound] = useState(editing ? [{ id: reminder.customer.id, name: reminder.customer.name, hasEmail: true, hasPhone: true, consent: true }] : []);

    useEffect(() => {
        if (editing) return undefined;
        const timer = setTimeout(() => axios.get(route('reminders.customers'), { params: { q: query } }).then((response) => setFound(response.data.customers)).catch(() => setFound([])), 250);

        return () => clearTimeout(timer);
    }, [query, editing]);

    const customer = found.find((item) => item.id === data.customer_id);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        form.transform((values) => ({ ...values, send_at: values.date && values.time ? `${values.date}T${values.time}` : '' }));
        editing ? form.put(route('reminders.update', reminder.id), options) : form.post(route('reminders.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar recordatorio' : 'Nuevo recordatorio'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Un seguimiento comercial a un cliente. Solo se envía a quien dio su consentimiento, y se vuelve a comprobar en el momento del envío. La fecha y la hora son de {timezone}.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                        {editing ? (
                            <div className="min-w-0 sm:col-span-2">
                                <p className="text-sm font-medium text-ink">Cliente</p>
                                <p className="mt-1 text-sm text-ink">{reminder.customer.name}</p>
                            </div>
                        ) : (
                            <>
                                <div className="min-w-0">
                                    <InputLabel htmlFor="rem_customer_q" value="Buscar cliente" />
                                    <TextInput id="rem_customer_q" className="mt-1" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Nombre del cliente" autoComplete="off" />
                                </div>
                                <div className="min-w-0">
                                    <InputLabel htmlFor="rem_customer" value="Cliente" />
                                    <Select
                                        id="rem_customer"
                                        className="mt-1"
                                        value={data.customer_id}
                                        onChange={(id) => setData('customer_id', id)}
                                        invalid={Boolean(errors.customer_id)}
                                        placeholder={found.length === 0 ? 'Ningún cliente coincide' : 'Elige un cliente'}
                                        options={found.map((item) => ({ value: item.id, label: `${item.name}${item.consent ? '' : ' (sin consentimiento)'}` }))}
                                    />
                                    <InputError message={errors.customer_id} className="mt-1" />
                                </div>
                            </>
                        )}
                    </div>
                    {customer && !customer.consent && <p className="-mt-2 text-xs text-warning">Este cliente no ha dado su consentimiento: el servidor rechazará el recordatorio.</p>}
                    {editing && <InputError message={errors.customer_id} className="-mt-2" />}

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

                    <MessageFields data={data} setData={setData} errors={errors} channels={channels} variables={variables} limits={limits} idPrefix="rem" />

                    <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                        <div className="min-w-0">
                            <InputLabel htmlFor="rem_related_type" value="Relacionado con (opcional)" />
                            <Select id="rem_related_type" className="mt-1" value={data.related_type} onChange={(type) => setData((current) => ({ ...current, related_type: type, related_id: type ? current.related_id : '' }))} options={[{ value: '', label: 'Nada' }, ...Object.entries(relatedTypes).map(([value, label]) => ({ value, label }))]} />
                        </div>
                        <div className="min-w-0">
                            <InputLabel htmlFor="rem_related_id" value="Número del registro" />
                            <TextInput id="rem_related_id" type="number" min="1" className="mt-1" value={data.related_id} onChange={(event) => setData('related_id', event.target.value)} invalid={Boolean(errors.related_id)} disabled={!data.related_type} />
                            <InputError message={errors.related_id} className="mt-1" />
                        </div>
                    </div>
                    <p className="-mt-2 text-xs text-ink-muted">Si es una cita o un pedido y se cancela antes del envío, el recordatorio no sale.</p>
                </div>

                <div className="flex flex-wrap justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cancelar</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Programar recordatorio'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
