import { Head, router, usePage } from '@inertiajs/react';
import { CalendarOff, Package, Pencil, Plus, Power, UserRound, Users } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import ExternalAgendaNotice from '@/Components/Booking/ExternalAgendaNotice';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import Tabs from '@/Components/Tabs';
import { TableWrap, Td, Th } from '@/Components/Table';
import useAccess from '@/Hooks/useAccess';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import ProfessionalModal from './Partials/ProfessionalModal';
import { ExceptionsPanel, ResourcesPanel } from './Partials/ResourcesAndExceptions';

const shortDays = { 1: 'Lun', 2: 'Mar', 3: 'Mié', 4: 'Jue', 5: 'Vie', 6: 'Sáb', 7: 'Dom' };

/** "Lun–Vie 09:00–17:00" style summary of a weekly schedule. */
function scheduleSummary(schedule) {
    if (schedule.length === 0) return 'Sin horario';
    const days = [...new Set(schedule.map((block) => block.weekday))];

    return `${days.map((day) => shortDays[day]).join(', ')} · ${schedule.length} tramo${schedule.length === 1 ? '' : 's'}`;
}

/** Profesionales y disponibilidad: who performs the services and when they work (or the external agenda's, read only). */
export default function Index({ capabilities, days, professionals, external, externalError, services, resources, exceptions }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const { errors } = usePage().props;
    const canManage = permissions.includes('manage-services') && !capabilities.external;
    const [editing, setEditing] = useState(null);

    const sections = [
        { id: 'profesionales', label: 'Profesionales', icon: UserRound, count: capabilities.external ? (external ?? []).length : professionals.length },
        !capabilities.external && { id: 'recursos', label: 'Recursos', icon: Package, count: resources.length },
        !capabilities.external && { id: 'excepciones', label: 'Días no laborables', icon: CalendarOff, count: exceptions.length },
    ].filter(Boolean);
    const [tab, selectTab] = useUrlTab(sections.map((section) => section.id));

    const toggle = async (professional) => {
        const confirmed = await confirm({
            description: professional.isActive ? `${professional.name} dejará de ofrecerse para nuevas reservas.${professional.upcomingCount > 0 ? ` Tiene ${professional.upcomingCount} cita(s) próximas que seguirán vigentes: reprográmalas o cancélalas.` : ' Sus citas existentes no cambian.'}` : `${professional.name} volverá a ofrecerse para reservas.`,
            confirmLabel: professional.isActive ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(professional.isActive ? 'professionals.deactivate' : 'professionals.activate', professional.id), {}, { preserveScroll: true });
    };

    const list = capabilities.external ? (external ?? []) : professionals;
    const serviceNames = Object.fromEntries(services.map((service) => [service.id, service.name]));

    const panels = [
        <Card key="pros" className="overflow-hidden">
            {list.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Users} title="Todavía no hay profesionales" description={capabilities.external ? 'La agenda externa no devolvió profesionales.' : 'Crea el primero con sus servicios y su horario.'}>
                        {canManage && <PrimaryButton type="button" onClick={() => setEditing({})}>Nuevo profesional</PrimaryButton>}
                    </EmptyState>
                </div>
            ) : (
                <TableWrap>
                    <thead>
                        <tr>
                            <Th>Profesional</Th>
                            {!capabilities.external && <Th>Servicios</Th>}
                            {!capabilities.external && <Th>Horario</Th>}
                            {!capabilities.external && <Th>Estado</Th>}
                            {canManage && <Th className="text-center">Acciones</Th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {list.map((professional) => (
                            <tr key={professional.id} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td>
                                    <p className="font-semibold text-ink">{professional.name}</p>
                                    {professional.specialty && <p className="text-xs text-ink-muted">{professional.specialty}</p>}
                                </Td>
                                {!capabilities.external && <Td className="max-w-xs truncate text-ink-muted">{professional.serviceIds.map((id) => serviceNames[id]).filter(Boolean).join(', ') || '—'}</Td>}
                                {!capabilities.external && <Td className="whitespace-nowrap text-ink-muted">{scheduleSummary(professional.schedule)}</Td>}
                                {!capabilities.external && (
                                    <Td>
                                        <Badge tone={professional.isActive ? 'green' : 'neutral'}>{professional.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                    </Td>
                                )}
                                {canManage && (
                                    <Td>
                                        <div className="flex items-center justify-center gap-1">
                                            <IconButton icon={Pencil} label="Editar" context={professional.name} onClick={() => setEditing(professional)} />
                                            <IconButton icon={Power} label={professional.isActive ? 'Desactivar' : 'Activar'} context={professional.name} tone={professional.isActive ? 'danger' : 'primary'} onClick={() => toggle(professional)} />
                                        </div>
                                    </Td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}
        </Card>,
        ...(capabilities.external ? [] : [<ResourcesPanel key="res" resources={resources} canManage={canManage} />, <ExceptionsPanel key="exc" exceptions={exceptions} professionals={professionals.filter((p) => p.isActive)} canManage={canManage} />]),
    ];

    return (
        <>
            <Head title="Profesionales" />

            <PageHeader title="Profesionales y disponibilidad" description="Quién presta cada servicio, cuándo trabaja y qué días no se puede reservar.">
                {canManage && tab === 0 && (
                    <PrimaryButton type="button" onClick={() => setEditing({})} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo profesional
                    </PrimaryButton>
                )}
            </PageHeader>

            <ExternalAgendaNotice capabilities={capabilities} error={externalError} what="Los profesionales y sus horarios" />
            {errors?.schedule && <InputError message={errors.schedule} />}

            <Tabs tabs={sections} selectedIndex={tab} onChange={selectTab}>
                {panels}
            </Tabs>

            {editing && <ProfessionalModal professional={editing} services={services} days={days} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
