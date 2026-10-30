import { Head, router } from '@inertiajs/react';
import { BellRing, History, Pencil, Plus, XCircle } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import DateField from '@/Components/DateField';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import useAccess from '@/Hooks/useAccess';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import ReminderAttemptsModal from './Partials/ReminderAttemptsModal';
import ReminderFormModal from './Partials/ReminderFormModal';

const TONES = { scheduled: 'blue', processing: 'blue', sent: 'green', failed: 'red', cancelled: 'neutral', skipped: 'amber', uncertain: 'amber' };

/**
 * Recordatorios: the commercial follow-ups the Workspace scheduled for its customers. Independent of Agenda (no appointment is confirmed or reminded here). The server decides
 * what each role may do and which channels are really available; this page only offers it. The list is paginated and filtered in the server.
 */
export default function Index({ reminders, perPageOptions, filters, channels, statuses, reasons, relatedTypes, variables, limits, timezone }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const can = { create: permissions.includes('create-reminders'), edit: permissions.includes('edit-reminders'), cancel: permissions.includes('cancel-reminders') };
    const [editing, setEditing] = useState(null); // null = closed, 'new' = creating, otherwise the reminder
    const [attemptsOf, setAttemptsOf] = useState(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const list = usePagedList({ section: 'reminders', only: ['reminders', 'filters'], meta: reminders.meta, perPageOptions, routeName: 'reminders.index', extra: { ...filters } });
    const change = (patch) => list.visit({ ...patch }, { restart: true });
    const filtered = Object.values(filters).some(Boolean);
    const anyChannel = channels.some((channel) => channel.available);

    const cancel = async (reminder) => {
        const confirmed = await confirm({ description: `«${reminder.name}» no se enviará. No se puede deshacer; puedes programar otro.`, confirmLabel: 'Cancelar recordatorio' });

        if (confirmed) router.post(route('reminders.cancel', reminder.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Recordatorios" />

            <PageHeader title="Recordatorios" description="Seguimientos a tus clientes en la fecha que elijas: por ejemplo, invitarlos a reservar de nuevo 15 días después de una atención. No son las confirmaciones de las citas.">
                {can.create && (
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2" disabled={!anyChannel}>
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo recordatorio
                    </PrimaryButton>
                )}
            </PageHeader>

            {!anyChannel && <Alert tone="warning">Ningún canal está disponible para enviar recordatorios: {channels.map((channel) => `${channel.label} (${channel.reason})`).join(' · ')}</Alert>}

            <Card className="space-y-4 p-4 sm:p-5">
                <div role="search" aria-label="Filtros de recordatorios" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <form className="sm:col-span-2 lg:col-span-1" onSubmit={(event) => { event.preventDefault(); change({ q: search || undefined }); }}>
                        <InputLabel htmlFor="rem_q" value="Nombre" />
                        <TextInput id="rem_q" className="mt-1" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar y pulsar Enter" maxLength={120} />
                    </form>
                    <div>
                        <InputLabel htmlFor="rem_status" value="Estado" />
                        <Select id="rem_status" value={filters.status ?? ''} onChange={(status) => change({ status: status || undefined })} options={[{ value: '', label: 'Todos los estados' }, ...Object.entries(statuses).map(([value, label]) => ({ value, label }))]} />
                    </div>
                    <div>
                        <InputLabel htmlFor="rem_channel" value="Canal" />
                        <Select id="rem_channel" value={filters.channel ?? ''} onChange={(channel) => change({ channel: channel || undefined })} options={[{ value: '', label: 'Todos los canales' }, ...channels.map((channel) => ({ value: channel.key, label: channel.label }))]} />
                    </div>
                    <DateField id="rem_from" label="Desde" value={filters.from ?? ''} max={filters.to || undefined} onChange={(from) => change({ from: from || undefined })} />
                    <DateField id="rem_to" label="Hasta" value={filters.to ?? ''} min={filters.from || undefined} onChange={(to) => change({ to: to || undefined })} />
                </div>
                {filtered && <SecondaryButton type="button" onClick={() => { setSearch(''); list.visit({ q: undefined, status: undefined, channel: undefined, from: undefined, to: undefined }, { restart: true }); }}>Limpiar filtros</SecondaryButton>}
            </Card>

            {reminders.data.length === 0 ? (
                <EmptyState icon={BellRing} title={filtered ? 'Ningún recordatorio coincide' : 'Todavía no hay recordatorios'} description={filtered ? 'Prueba con otros filtros.' : 'Programa el primero: eliges al cliente, el canal, el mensaje y el día y la hora de envío (hora de ' + timezone + ').'}>
                    {!filtered && can.create && anyChannel && (
                        <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                            <Plus className="size-4" aria-hidden="true" />
                            Nuevo recordatorio
                        </PrimaryButton>
                    )}
                </EmptyState>
            ) : (
                <Card className="overflow-hidden">
                    <TableWrap busy={list.loading}>
                        <thead>
                            <tr>
                                <Th>Recordatorio</Th>
                                <Th>Cliente</Th>
                                <Th>Canal</Th>
                                <Th>Envío</Th>
                                <Th>Estado</Th>
                                <Th className="text-right"><span className="sr-only">Acciones</span></Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {reminders.data.map((reminder) => (
                                <tr key={reminder.id}>
                                    <Td>
                                        <p className="font-semibold text-ink">{reminder.name}</p>
                                        {reminder.purpose && <p className="max-w-xs truncate text-xs text-ink-muted" title={reminder.purpose}>{reminder.purpose}</p>}
                                        {reminder.relatedType && <p className="text-xs text-ink-muted">{relatedTypes[reminder.relatedType]} #{reminder.relatedId}</p>}
                                    </Td>
                                    <Td className="text-ink">{reminder.customer.name}</Td>
                                    <Td className="text-ink">{channels.find((channel) => channel.key === reminder.channel)?.label ?? reminder.channel}</Td>
                                    <Td className="whitespace-nowrap text-ink">{reminder.sendAt}</Td>
                                    <Td>
                                        <Badge tone={TONES[reminder.status] ?? 'neutral'}>{statuses[reminder.status] ?? reminder.status}</Badge>
                                        {reminder.reason && <p className="mt-1 max-w-xs text-xs text-ink-muted">{reasons[reminder.reason] ?? reminder.reason}</p>}
                                    </Td>
                                    <Td className="text-right">
                                        <div className="flex justify-end gap-1">
                                            <IconButton icon={History} label="Ver intentos" context={reminder.name} onClick={() => setAttemptsOf(reminder)} />
                                            {reminder.canEdit && can.edit && <IconButton icon={Pencil} label="Editar" context={reminder.name} onClick={() => setEditing(reminder)} />}
                                            {reminder.canEdit && can.cancel && <IconButton icon={XCircle} label="Cancelar" context={reminder.name} tone="danger" onClick={() => cancel(reminder)} />}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </TableWrap>
                    <Pagination {...list.paginationProps} noun="recordatorios" />
                </Card>
            )}

            {editing && <ReminderFormModal key={editing === 'new' ? 'new' : editing.id} reminder={editing === 'new' ? null : editing} channels={channels} variables={variables} relatedTypes={relatedTypes} limits={limits} timezone={timezone} onClose={() => setEditing(null)} />}
            {attemptsOf && <ReminderAttemptsModal reminder={attemptsOf} onClose={() => setAttemptsOf(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
