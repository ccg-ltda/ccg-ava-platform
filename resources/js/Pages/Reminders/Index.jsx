import { Head, router } from '@inertiajs/react';
import { BellRing, History, Pencil, Plus, Workflow, XCircle } from 'lucide-react';
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
import Tabs from '@/Components/Tabs';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import useAccess from '@/Hooks/useAccess';
import useUrlTab from '@/Hooks/useUrlTab';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import ReminderAttemptsModal from './Partials/ReminderAttemptsModal';
import ReminderFormModal from './Partials/ReminderFormModal';
import RulesPanel from './Partials/RulesPanel';

const TONES = { scheduled: 'blue', processing: 'blue', sent: 'green', failed: 'red', cancelled: 'neutral', skipped: 'amber', uncertain: 'amber' };

/**
 * Recordatorios: the commercial follow-ups the Workspace scheduled for its customers. Independent of Agenda (no appointment is confirmed or reminded here). The server decides
 * what each role may do and which channels are really available; this page only offers it. The list is paginated and filtered in the server.
 */
export default function Index({ reminders, perPageOptions, filters, channels, statuses, reasons, relatedTypes, variables, limits, timezone, rules = null }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const can = { create: permissions.includes('create-reminders'), edit: permissions.includes('edit-reminders'), cancel: permissions.includes('cancel-reminders') };
    const ruleCan = { create: permissions.includes('create-reminder-rules'), edit: permissions.includes('edit-reminder-rules'), activate: permissions.includes('activate-reminder-rules') };
    const tabs = [{ id: 'recordatorios', label: 'Recordatorios', icon: BellRing }, ...(rules ? [{ id: 'reglas', label: 'Reglas automáticas', icon: Workflow, count: rules.data.length }] : [])];
    const [tab, selectTab] = useUrlTab(tabs.map((item) => item.id));
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

    const remindersView = (
        <div className="space-y-6">
            {!anyChannel && <Alert tone="warning">Ningún canal está disponible para enviar recordatorios: {channels.map((channel) => `${channel.label} (${channel.reason})`).join(' · ')}</Alert>}

            <Card className="p-4 sm:p-5">
                <div role="search" aria-label="Filtros de recordatorios" className="grid items-end gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <form className="min-w-0" onSubmit={(event) => { event.preventDefault(); change({ q: search || undefined }); }}>
                        <InputLabel htmlFor="rem_q" value="Nombre" />
                        <TextInput id="rem_q" className="mt-1" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar y pulsar Enter" maxLength={120} />
                    </form>
                    <div className="min-w-0">
                        <InputLabel htmlFor="rem_status" value="Estado" />
                        <Select id="rem_status" className="mt-1" value={filters.status ?? ''} onChange={(status) => change({ status: status || undefined })} options={[{ value: '', label: 'Todos' }, ...Object.entries(statuses).map(([value, label]) => ({ value, label }))]} />
                    </div>
                    <div className="min-w-0">
                        <InputLabel htmlFor="rem_channel" value="Canal" />
                        <Select id="rem_channel" className="mt-1" value={filters.channel ?? ''} onChange={(channel) => change({ channel: channel || undefined })} options={[{ value: '', label: 'Todos' }, ...channels.map((channel) => ({ value: channel.key, label: channel.label }))]} />
                    </div>
                    <DateField id="rem_from" label="Desde" value={filters.from ?? ''} max={filters.to || undefined} onChange={(from) => change({ from: from || undefined })} />
                    <DateField id="rem_to" label="Hasta" value={filters.to ?? ''} min={filters.from || undefined} onChange={(to) => change({ to: to || undefined })} />
                    <div className="min-w-0">
                        <SecondaryButton type="button" className="w-full justify-center" disabled={!filtered} onClick={() => { setSearch(''); list.visit({ q: undefined, status: undefined, channel: undefined, from: undefined, to: undefined }, { restart: true }); }}>Limpiar filtros</SecondaryButton>
                    </div>
                </div>
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
                                        {reminder.ruleId && <p className="text-xs text-ink-muted">Regla automática</p>}
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
                                            {reminder.canCancel && can.cancel && <IconButton icon={XCircle} label="Cancelar" context={reminder.name} tone="danger" onClick={() => cancel(reminder)} />}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </TableWrap>
                    <Pagination {...list.paginationProps} noun="recordatorios" />
                </Card>
            )}
        </div>
    );

    return (
        <>
            <Head title="Recordatorios" />

            <PageHeader title="Recordatorios" description="Seguimientos a tus clientes en la fecha que elijas: por ejemplo, invitarlos a reservar de nuevo 15 días después de una atención. No son las confirmaciones de las citas.">
                {tab === 0 && can.create && (
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2" disabled={!anyChannel}>
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo recordatorio
                    </PrimaryButton>
                )}
            </PageHeader>

            {rules ? (
                <Tabs tabs={tabs} selectedIndex={tab} onChange={selectTab}>
                    {remindersView}
                    <RulesPanel rules={rules} channels={channels} variables={variables} limits={limits} timezone={timezone} can={ruleCan} />
                </Tabs>
            ) : (
                remindersView
            )}

            {editing && <ReminderFormModal key={editing === 'new' ? 'new' : editing.id} reminder={editing === 'new' ? null : editing} channels={channels} variables={variables} relatedTypes={relatedTypes} limits={limits} timezone={timezone} onClose={() => setEditing(null)} />}
            {attemptsOf && <ReminderAttemptsModal reminder={attemptsOf} onClose={() => setAttemptsOf(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
