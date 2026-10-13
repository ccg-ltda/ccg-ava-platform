import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { dayLabel, getJson } from '@/config/bookings';

/**
 * Picks WHEN: the free starts the agenda really offers for a service (and optionally a professional) over a few days. Nothing is
 * typed by hand: only a start the server computed can be chosen, and the server checks it again when it is saved.
 */
function SlotPicker({ serviceId, professionalId, from, value, onPick, ignoreAppointment }) {
    const [state, setState] = useState({ loading: false, slots: [], error: null });

    useEffect(() => {
        if (!serviceId) return undefined;
        let live = true;
        setState({ loading: true, slots: [], error: null });

        getJson(route('bookings.availability'), { service_id: serviceId, professional_id: professionalId || undefined, from, days: 7, ignore_appointment: ignoreAppointment })
            .then((body) => live && setState({ loading: false, slots: body.slots, error: null }))
            .catch((error) => live && setState({ loading: false, slots: [], error: error.message }));

        return () => {
            live = false;
        };
    }, [serviceId, professionalId, from, ignoreAppointment]);

    if (!serviceId) return <p className="text-sm text-ink-muted">Elige un servicio para ver los horarios libres.</p>;
    if (state.loading) return <p className="text-sm text-ink-muted" role="status">Buscando horarios…</p>;
    if (state.error) return <Alert tone="warning">{state.error}</Alert>;
    if (state.slots.length === 0) return <p className="text-sm text-ink-muted">No hay horarios libres en estos días. Prueba con otra fecha o profesional.</p>;

    const byDay = state.slots.reduce((groups, slot) => ({ ...groups, [slot.date]: [...(groups[slot.date] ?? []), slot] }), {});

    return (
        <div className="max-h-64 space-y-3 overflow-y-auto pr-1">
            {Object.entries(byDay).map(([date, slots]) => (
                <div key={date}>
                    <p className="text-xs font-semibold tracking-wide text-ink-muted uppercase">{dayLabel(date)}</p>
                    <div className="mt-1.5 flex flex-wrap gap-2">
                        {slots.map((slot) => {
                            const picked = value?.startsAt === slot.startsAt && value?.professionalId === slot.professionalId;

                            return (
                                <button
                                    key={`${slot.startsAt}-${slot.professionalId}`}
                                    type="button"
                                    aria-pressed={picked}
                                    onClick={() => onPick(slot)}
                                    className={`rounded-lg border px-2.5 py-1.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary ${picked ? 'border-primary bg-primary text-white' : 'border-field text-ink hover:border-primary/50'}`}
                                >
                                    {slot.label}
                                    {!professionalId && <span className={`ml-1.5 text-xs ${picked ? 'text-white/80' : 'text-ink-muted'}`}>{slot.professionalName}</span>}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

/** New appointment, or a new time for an existing one (`appointment`). Saving goes through the same rules as every other channel. */
export default function AppointmentDialog({ appointment, options, today, onClose }) {
    const rescheduling = Boolean(appointment);
    const [serviceId, setServiceId] = useState(appointment?.serviceId ?? '');
    const [professionalId, setProfessionalId] = useState(appointment?.professionalId ?? '');
    const [from, setFrom] = useState(today);
    const [slot, setSlot] = useState(null);
    const [customer, setCustomer] = useState({ name: '', phone: '', email: '' });
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const services = options.services.map((service) => ({ value: service.id, label: service.name }));
    const professionals = [{ value: '', label: 'Cualquiera disponible' }, ...options.professionals.map((professional) => ({ value: professional.id, label: professional.name }))];

    const submit = (event) => {
        event.preventDefault();
        if (!slot) return setErrors({ starts_at: 'Elige un horario libre.' });

        const payload = rescheduling
            ? { starts_at: slot.startsAt, professional_id: slot.professionalId ?? undefined }
            : { service_id: serviceId, professional_id: slot.professionalId ?? undefined, starts_at: slot.startsAt, customer, notes };

        router.post(rescheduling ? route('bookings.reschedule', appointment.id) : route('bookings.store'), payload, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (bag) => setErrors(bag),
            onSuccess: (page) => {
                if (!page.props.flash?.error) onClose();
            },
        });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{rescheduling ? 'Reprogramar cita' : 'Nueva cita'}</h2>
                    {rescheduling && <p className="mt-1 text-sm text-ink-muted">{appointment.service} · {appointment.dateLabel} {appointment.timeLabel}</p>}
                </header>
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-8">
                    {options.error && <Alert tone="warning">{options.error}</Alert>}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {!rescheduling && (
                            <div>
                                <InputLabel htmlFor="appt_service" value="Servicio" />
                                <Select id="appt_service" className="mt-1" value={serviceId} onChange={(value) => { setServiceId(value); setSlot(null); }} options={services} placeholder="Elige un servicio" invalid={Boolean(errors.service_id)} />
                                <InputError message={errors.service_id} className="mt-1" />
                            </div>
                        )}
                        <div>
                            <InputLabel htmlFor="appt_pro" value="Profesional" />
                            <Select id="appt_pro" className="mt-1" value={professionalId} onChange={(value) => { setProfessionalId(value); setSlot(null); }} options={professionals} />
                        </div>
                        <div>
                            <InputLabel htmlFor="appt_from" value="Buscar desde" />
                            <TextInput id="appt_from" type="date" className="mt-1" value={from} min={today} onChange={(e) => { setFrom(e.target.value || today); setSlot(null); }} />
                        </div>
                    </div>

                    <div>
                        <p className="mb-2 text-sm font-medium text-ink">Horarios libres</p>
                        <SlotPicker serviceId={serviceId} professionalId={professionalId} from={from} value={slot} onPick={setSlot} ignoreAppointment={appointment?.id} />
                        <InputError message={errors.starts_at} className="mt-1" />
                    </div>

                    {!rescheduling && (
                        <fieldset className="space-y-4 rounded-xl border border-line p-4">
                            <legend className="px-1 text-sm font-medium text-ink">Quién reserva</legend>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="appt_name" value="Nombre" />
                                    <TextInput id="appt_name" className="mt-1" value={customer.name} maxLength={120} onChange={(e) => setCustomer({ ...customer, name: e.target.value })} invalid={Boolean(errors['customer.name'])} />
                                    <InputError message={errors['customer.name']} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="appt_phone" value="Teléfono" />
                                    <TextInput id="appt_phone" type="tel" className="mt-1" value={customer.phone} maxLength={32} onChange={(e) => setCustomer({ ...customer, phone: e.target.value })} invalid={Boolean(errors['customer.phone'])} />
                                    <InputError message={errors['customer.phone']} className="mt-1" />
                                </div>
                                <div className="sm:col-span-2">
                                    <InputLabel htmlFor="appt_email" value="Correo (opcional)" />
                                    <TextInput id="appt_email" type="email" className="mt-1" value={customer.email} maxLength={160} onChange={(e) => setCustomer({ ...customer, email: e.target.value })} invalid={Boolean(errors['customer.email'])} />
                                    <InputError message={errors['customer.email']} className="mt-1" />
                                </div>
                            </div>
                            <div>
                                <InputLabel htmlFor="appt_notes" value="Nota (opcional)" />
                                <Textarea id="appt_notes" rows={2} className="mt-1" value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} />
                            </div>
                        </fieldset>
                    )}
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={saving || !slot}>
                        {rescheduling ? 'Reprogramar' : 'Crear cita'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
