import { Head } from '@inertiajs/react';
import { CalendarDays, Settings, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import SearchInput from '@/Components/SearchInput';
import Tabs from '@/Components/Tabs';
import { TableWrap, Td, Th } from '@/Components/Table';
import useAccess from '@/Hooks/useAccess';
import usePagedList from '@/Hooks/usePagedList';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import AgendaPanel from './Partials/AgendaPanel';
import SettingsPanel from './Partials/SettingsPanel';

/** Who booked: the minimum needed to identify and contact them (name, phone, email). Deliberately not a CRM. */
function CustomersPanel({ customers, filters, perPageOptions }) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);
    const list = usePagedList({ tab: 'clientes', section: 'customers', only: ['customers', 'filters'], meta: customers.meta, perPageOptions, routeName: 'bookings.index', extra: { search: search || undefined, from: filters.from, days: filters.days } });

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return undefined;
        }

        const timer = setTimeout(() => list.visit({ search: search || undefined }, { restart: true }), 300);

        return () => clearTimeout(timer);
    }, [search]);

    return (
        <Card className="overflow-hidden">
            <div className="border-b border-line p-4 sm:px-6">
                <SearchInput className="w-full sm:w-72" value={search} onChange={setSearch} placeholder="Buscar por nombre, teléfono o correo" label="Buscar cliente" />
            </div>
            {customers.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState icon={Users} title={filters.search ? 'Ningún cliente coincide' : 'Todavía no hay clientes'} description="Aparecen aquí quienes reservan: solo su nombre y sus datos de contacto." />
                </div>
            ) : (
                <TableWrap busy={list.loading}>
                    <thead>
                        <tr>
                            <Th>Nombre</Th>
                            <Th>Teléfono</Th>
                            <Th>Correo</Th>
                            <Th>Citas</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {customers.data.map((customer) => (
                            <tr key={customer.id} className="hover:bg-canvas/70">
                                <Td className="font-semibold text-ink">{customer.name}</Td>
                                <Td className="whitespace-nowrap text-ink-muted">{customer.phone ?? '—'}</Td>
                                <Td className="text-ink-muted">{customer.email ?? '—'}</Td>
                                <Td className="text-ink-muted">{customer.appointments}</Td>
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}
            <Pagination noun="clientes" {...list.paginationProps} />
        </Card>
    );
}

/** Agenda y reservas: the calendar of appointments, who booked and the booking rules of the Workspace. */
export default function Index({ capabilities, filters, appointments, options, customers, settings, perPageOptions, today }) {
    const { permissions } = useAccess();
    // Creating, editing (confirm, reschedule, mark) and cancelling an appointment are independent; the rules and the public page are a fourth.
    const can = { create: permissions.includes('create-bookings'), edit: permissions.includes('edit-bookings'), cancel: permissions.includes('cancel-bookings') };
    const sections = [
        { id: 'agenda', label: 'Agenda', icon: CalendarDays, count: appointments.length },
        { id: 'clientes', label: 'Clientes', icon: Users, count: customers.meta.total },
        { id: 'configuracion', label: 'Configuración', icon: Settings },
    ];
    const [tab, selectTab] = useUrlTab(sections.map((section) => section.id));

    return (
        <>
            <Head title="Agenda y reservas" />

            <PageHeader title="Agenda y reservas" description="Citas, quién reserva y las reglas de reserva de tu negocio." />

            <Tabs tabs={sections} selectedIndex={tab} onChange={selectTab}>
                <AgendaPanel appointments={appointments} filters={filters} options={options} capabilities={capabilities} today={today} can={can} />
                <CustomersPanel customers={customers} filters={filters} perPageOptions={perPageOptions} />
                <SettingsPanel settings={settings} capabilities={capabilities} canManage={permissions.includes('manage-booking-settings')} />
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
