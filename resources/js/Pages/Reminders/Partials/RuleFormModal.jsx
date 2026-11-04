import { useForm } from '@inertiajs/react';
import EntityPicker from '@/Components/EntityPicker';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import TimeField from '@/Components/TimeField';
import MessageFields from './MessageFields';

/**
 * Create / edit an automatic rule: «N hours, days or weeks after a customer had service X, send this message». Nobody picks customers: Ava finds who had the service from the
 * appointments Agenda marked as done. The server validates the services, the channel and the message again, and applies the rule only to services done from the moment it is on.
 */
export default function RuleFormModal({ rule, rules, channels, variables, limits, timezone, onClose }) {
    const editing = Boolean(rule);
    const firstAvailable = channels.find((channel) => channel.available)?.key ?? '';
    const form = useForm({
        name: rule?.name ?? '',
        service_ids: rule?.services.map((service) => service.id) ?? [],
        delay_amount: rule?.delayAmount ?? 30,
        delay_unit: rule?.delayUnit ?? 'days',
        send_time: rule?.sendTime ?? rules.defaultSendTime,
        channel: rule?.channel ?? firstAvailable,
        subject: rule?.subject ?? '',
        body: rule?.body ?? '',
        template_name: rule?.templateName ?? '',
        template_language: rule?.templateLanguage ?? 'es',
        template_params: rule?.templateParams ?? [],
    });
    const { data, setData, errors } = form;
    // The services the server offered for THIS Workspace, plus the ones the rule already has (even if one was deactivated since, so editing never drops it silently).
    const known = new Map(rules.services.map((service) => [service.id, service]));
    rule?.services.forEach((service) => known.has(service.id) || known.set(service.id, service));
    const serviceOptions = [...known.values()].map((service) => ({ value: service.id, label: service.name })).sort((x, y) => x.label.localeCompare(y.label, 'es'));
    const daily = data.delay_unit !== 'hours';

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        form.transform((values) => ({ ...values, delay_amount: Number(values.delay_amount) || 0, send_time: values.delay_unit === 'hours' ? null : values.send_time || null }));
        editing ? form.put(route('reminders.rules.update', rule.id), options) : form.post(route('reminders.rules.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar regla' : 'Nueva regla automática'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        Ava programa un recordatorio para cada cliente que realice uno de los servicios elegidos, con su propia fecha. Solo cuentan los servicios que Agenda marca como realizados desde que la regla está activa, y solo se envía a quien dio su consentimiento.
                    </p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    <div className="min-w-0">
                        <InputLabel htmlFor="rule_name" value="Nombre de la regla" />
                        <TextInput id="rule_name" className="mt-1" value={data.name} onChange={(event) => setData('name', event.target.value)} invalid={Boolean(errors.name)} maxLength={limits.name} placeholder="Recordatorio de mantenimiento de uñas" autoComplete="off" />
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    <div className="min-w-0">
                        <InputLabel htmlFor="rule_services" value="Servicios que activan la regla" />
                        <EntityPicker
                            id="rule_services"
                            className="mt-1"
                            value={data.service_ids}
                            onChange={(ids) => setData('service_ids', ids)}
                            options={serviceOptions}
                            placeholder={serviceOptions.length === 0 ? 'No hay servicios activos' : 'Buscar servicio por nombre'}
                            emptyLabel={serviceOptions.length === 0 ? 'Este Workspace no tiene servicios activos: crea uno en Servicios.' : 'Ningún servicio coincide con la búsqueda'}
                            noneLabel="Ningún servicio elegido todavía"
                            invalid={Boolean(errors.service_ids)}
                            closeOnSelect
                        />
                        <InputError message={errors.service_ids} className="mt-1" />
                    </div>

                    <div className="grid gap-x-5 gap-y-4 sm:grid-cols-3">
                        <div className="min-w-0">
                            <InputLabel htmlFor="rule_amount" value="Esperar" />
                            <TextInput id="rule_amount" type="number" min="1" inputMode="numeric" className="mt-1" value={data.delay_amount} onChange={(event) => setData('delay_amount', event.target.value)} invalid={Boolean(errors.delay_amount)} />
                            <InputError message={errors.delay_amount} className="mt-1" />
                        </div>
                        <div className="min-w-0">
                            <InputLabel htmlFor="rule_unit" value="Unidad" />
                            <Select id="rule_unit" className="mt-1" value={data.delay_unit} onChange={(unit) => setData('delay_unit', unit)} invalid={Boolean(errors.delay_unit)} options={Object.entries(rules.delayUnits).map(([value, label]) => ({ value, label }))} />
                            <InputError message={errors.delay_unit} className="mt-1" />
                        </div>
                        <TimeField id="rule_time" label="Hora de envío" value={daily ? data.send_time : ''} onChange={(value) => setData('send_time', value)} disabled={!daily} error={errors.send_time} />
                    </div>
                    <p className="-mt-2 text-xs text-ink-muted">
                        La espera cuenta desde el inicio de la cita realizada de cada cliente. {daily ? `Se envía a la hora indicada (hora de ${timezone}).` : 'Se envía pasadas esas horas.'} Si Ava llega tarde (por ejemplo, sin canal disponible) todavía lo envía durante {rules.graceHours} h; después lo omite.
                    </p>

                    <MessageFields data={data} setData={setData} errors={errors} channels={channels} variables={variables} limits={limits} idPrefix="rule" />
                </div>

                <div className="flex flex-wrap justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cancelar</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>{editing ? 'Guardar cambios' : 'Crear regla'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
