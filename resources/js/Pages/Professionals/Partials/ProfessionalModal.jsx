import { useForm } from '@inertiajs/react';
import EntityPicker from '@/Components/EntityPicker';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import ScheduleEditor from './ScheduleEditor';

/** Create or edit a professional: who they are, the services they perform and their weekly schedule. */
export default function ProfessionalModal({ professional, services, days, locations = [], onClose }) {
    const editing = Boolean(professional?.id);
    const form = useForm({
        name: professional?.name ?? '',
        specialty: professional?.specialty ?? '',
        phone: professional?.phone ?? '',
        service_ids: professional?.serviceIds ?? [],
        schedule: professional?.schedule ?? [1, 2, 3, 4, 5].map((weekday) => ({ weekday, startsAt: '09:00', endsAt: '17:00', locationId: null })),
    });
    const { data, setData, errors } = form;
    const serviceOptions = services.map((service) => ({ value: service.id, label: service.name }));

    const submit = (event) => {
        event.preventDefault();
        form.transform((payload) => ({ ...payload, schedule: payload.schedule.map((block) => ({ weekday: block.weekday, starts_at: block.startsAt, ends_at: block.endsAt, location_id: block.locationId || null })) }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) form.put(route('professionals.update', professional.id), options);
        else form.post(route('professionals.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar profesional' : 'Nuevo profesional'}</h2>
                </header>
                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-8">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="pro_name" value="Nombre" />
                            <TextInput id="pro_name" className="mt-1" value={data.name} maxLength={120} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="pro_specialty" value="Especialidad (opcional)" />
                            <TextInput id="pro_specialty" className="mt-1" value={data.specialty} maxLength={120} onChange={(e) => setData('specialty', e.target.value)} />
                        </div>
                        <div>
                            <InputLabel htmlFor="pro_phone" value="Teléfono (opcional)" />
                            <TextInput id="pro_phone" className="mt-1" placeholder="+573001112233" value={data.phone} maxLength={32} onChange={(e) => setData('phone', e.target.value)} invalid={Boolean(errors.phone)} />
                            <InputError message={errors.phone} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="pro_services" value="Servicios que presta" />
                        {services.length === 0 ? (
                            <p className="mt-1 text-sm text-ink-muted">Primero crea servicios en la página Servicios.</p>
                        ) : (
                            <EntityPicker id="pro_services" className="mt-1" options={serviceOptions} value={data.service_ids} onChange={(ids) => setData('service_ids', ids)} placeholder="Buscar servicio" noneLabel="Ningún servicio elegido" invalid={Boolean(errors.service_ids || errors['service_ids.0'])} error={errors['service_ids.0'] ?? errors.service_ids} />
                        )}
                    </div>

                    <ScheduleEditor days={days} locations={locations} value={data.schedule} onChange={(schedule) => setData('schedule', schedule)} error={errors.schedule} />
                </div>
                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {editing ? 'Guardar cambios' : 'Crear profesional'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
