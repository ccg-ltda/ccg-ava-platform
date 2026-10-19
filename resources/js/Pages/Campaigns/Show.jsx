import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Ban, ClipboardCheck, FlaskConical, Save } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import useAccess from '@/Hooks/useAccess';
import useFormatDateTime from '@/Hooks/useFormatDateTime';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import AudienceSummary from './Partials/AudienceSummary';
import MessageEditor from './Partials/MessageEditor';
import RecipientPicker from './Partials/RecipientPicker';
import StatusBadge from './Partials/StatusBadge';

const RECIPIENT_STATUS = { pending: ['Pendiente', 'neutral'], excluded: ['Excluido', 'amber'], simulated: ['Simulado', 'violet'] };

const optionsOf = (map, empty) => [...(empty ? [{ value: '', label: empty }] : []), ...Object.entries(map).map(([value, label]) => ({ value, label }))];

function Recipients({ campaign, recipients, exclusions, perPageOptions }) {
    const list = usePagedList({ section: 'recipients', only: ['recipients'], meta: recipients.meta, perPageOptions, routeName: 'campaigns.show', routeParams: campaign.id });

    return (
        <Card className="overflow-hidden">
            <h2 className="border-b border-line px-4 py-4 text-base font-bold text-ink sm:px-6">Destinatarios</h2>
            <TableWrap busy={list.loading}>
                <thead>
                    <tr>
                        <Th>Cliente</Th>
                        <Th>Estado</Th>
                        <Th>Motivo o resultado</Th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {recipients.data.map((recipient) => {
                        const [label, tone] = RECIPIENT_STATUS[recipient.status] ?? [recipient.status, 'neutral'];

                        return (
                            <tr key={recipient.id}>
                                <Td>
                                    <Link href={route('customers.show', recipient.customerId)} className="font-semibold text-ink hover:text-primary">
                                        {recipient.name}
                                    </Link>
                                </Td>
                                <Td>
                                    <Badge tone={tone}>{label}</Badge>
                                </Td>
                                <Td className="text-ink-muted">{recipient.reason ? exclusions[recipient.reason] : (recipient.error ?? '—')}</Td>
                            </tr>
                        );
                    })}
                </tbody>
            </TableWrap>
            <Pagination {...list.paginationProps} noun="destinatarios" />
        </Card>
    );
}

/** One campaign: its draft form, the review of its audience and its steps (prepare, simulate, cancel). It never sends anything. */
export default function Show({ campaign, summary, recipients, picker, delivery, templates, availability, sample, options, perPageOptions }) {
    const confirm = useConfirm();
    const formatDateTime = useFormatDateTime();
    const { permissions } = useAccess();
    const canEdit = permissions.includes('edit-campaigns');
    const canPrepare = permissions.includes('prepare-campaigns');
    const canCancel = permissions.includes('cancel-campaigns');
    const draft = campaign.status === 'draft';
    const editable = draft && canEdit;
    const variables = Object.keys(availability).map((key) => ({ key, label: availability[key].label }));
    const form = useForm({
        name: campaign.name,
        channel: campaign.channel,
        message_template_id: campaign.templateId ?? '',
        body: campaign.body,
        audience: campaign.audience,
        filters: { source: '', tag: '', identity_channel: '', created_from: '', created_to: '', ...campaign.filters },
        customer_ids: campaign.customerIds,
    });
    const { data, setData, errors } = form;
    const setFilter = (key, value) => setData('filters', { ...data.filters, [key]: value });

    const save = () => {
        form.transform((values) => ({ ...values, message_template_id: values.message_template_id || null }));
        form.put(route('campaigns.update', campaign.id), { preserveScroll: true });
    };

    const pickTemplate = (value) => {
        const template = templates.find((item) => item.id === value);
        setData((previous) => ({ ...previous, message_template_id: value, body: template ? template.body : previous.body }));
    };

    const step = async (action, description, confirmLabel) => {
        const confirmed = await confirm({ description, confirmLabel });

        if (confirmed) router.post(route(`campaigns.${action}`, campaign.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title={campaign.name} />

            <Link href={route('campaigns.index', { tab: 'campaigns' })} className="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Campañas
            </Link>

            <PageHeader title={campaign.name} description={`Canal: ${options.channels[campaign.channel]}`}>
                <StatusBadge status={campaign.status} labels={options.statuses} />
            </PageHeader>

            <Alert tone="info">
                <p className="font-semibold">No se envía ningún mensaje.</p>
                <p>{delivery.reason}</p>
                <p className="mt-1 text-ink-muted">
                    Borrador: se edita. Preparada: los destinatarios quedan fijados y revisados. Simulada: ensayo sin envío. {campaign.preparedAt && `Preparada el ${formatDateTime(campaign.preparedAt)}. `}
                    {campaign.simulatedAt && `Simulada el ${formatDateTime(campaign.simulatedAt)}. `}
                    {campaign.cancelledAt && `Cancelada el ${formatDateTime(campaign.cancelledAt)}.`}
                </p>
            </Alert>
            {errors.status && <InputError message={errors.status} />}

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="space-y-5 p-5 sm:p-6 lg:col-span-2">
                    <h2 className="text-base font-bold text-ink">Mensaje y destinatarios</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel htmlFor="campaign_name" value="Nombre" />
                            <TextInput id="campaign_name" className="mt-1" value={data.name} maxLength={120} disabled={!editable} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="campaign_channel" value="Canal" />
                            <Select id="campaign_channel" className="mt-1" disabled={!editable} value={data.channel} onChange={(value) => setData('channel', value)} options={optionsOf(options.channels)} />
                            <InputError message={errors.channel} className="mt-1" />
                        </div>
                    </div>

                    {editable && (
                        <div>
                            <InputLabel htmlFor="campaign_template" value="Cargar una plantilla (copia su mensaje)" />
                            <Select id="campaign_template" className="mt-1" value={data.message_template_id} onChange={pickTemplate} options={[{ value: '', label: 'Mensaje propio' }, ...templates.map((template) => ({ value: template.id, label: template.name }))]} />
                        </div>
                    )}

                    <MessageEditor id="campaign_message" value={data.body} onChange={(value) => setData('body', value)} error={errors.body} variables={variables} availability={availability} sample={sample} max={options.bodyMax} disabled={!editable} />

                    <fieldset disabled={!editable} className="space-y-3">
                        <legend className="text-sm font-medium text-ink">Destinatarios</legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {[
                                ['all', 'Todos los clientes elegibles', 'Los del Workspace que cumplan los filtros y hayan autorizado recibir mensajes.'],
                                ['selected', 'Elegir clientes uno a uno', 'Solo los que marques, siempre que sean elegibles.'],
                            ].map(([value, title, hint]) => (
                                <label key={value} className="flex cursor-pointer items-start gap-3 rounded-lg border border-field p-3 text-sm has-checked:border-primary/50 has-checked:bg-primary-soft/40">
                                    <input type="radio" name="audience" className="mt-1 size-4 accent-primary" checked={data.audience === value} onChange={() => setData('audience', value)} />
                                    <span>
                                        <span className="block font-semibold text-ink">{title}</span>
                                        <span className="block text-xs text-ink-muted">{hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>

                        {data.audience === 'all' && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="filter_source" value="Origen" />
                                    <Select id="filter_source" className="mt-1" value={data.filters.source} onChange={(value) => setFilter('source', value)} options={optionsOf(options.sources, 'Todos')} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="filter_tag" value="Etiqueta" />
                                    <Select id="filter_tag" className="mt-1" value={data.filters.tag} onChange={(value) => setFilter('tag', value)} options={[{ value: '', label: 'Todas' }, ...options.tags.map((tag) => ({ value: tag, label: tag }))]} />
                                </div>
                                <div>
                                    <InputLabel htmlFor="filter_identity" value="Con identificador en el canal" />
                                    <Select id="filter_identity" className="mt-1" value={data.filters.identity_channel} onChange={(value) => setFilter('identity_channel', value)} options={optionsOf(options.identityChannels, 'Cualquiera')} />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel htmlFor="filter_from" value="Alta desde" />
                                        <TextInput id="filter_from" type="date" className="mt-1" value={data.filters.created_from} onChange={(e) => setFilter('created_from', e.target.value)} />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="filter_to" value="Alta hasta" />
                                        <TextInput id="filter_to" type="date" className="mt-1" value={data.filters.created_to} onChange={(e) => setFilter('created_to', e.target.value)} invalid={Boolean(errors['filters.created_to'])} />
                                    </div>
                                    <InputError message={errors['filters.created_to']} className="col-span-2" />
                                </div>
                            </div>
                        )}

                        {data.audience === 'selected' && picker && (
                            <RecipientPicker campaignId={campaign.id} picker={picker} selected={data.customer_ids} onChange={(ids) => setData('customer_ids', ids)} exclusions={options.exclusions} perPageOptions={perPageOptions} max={options.maxSelected} />
                        )}
                        {data.audience === 'selected' && !picker && <Alert tone="info">Guarda el borrador para elegir clientes uno a uno.</Alert>}
                        <InputError message={errors.customer_ids} />
                    </fieldset>

                    {editable && (
                        <div className="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
                            <PrimaryButton type="button" className="gap-2" onClick={save} disabled={form.processing || !form.isDirty}>
                                <Save className="size-4" aria-hidden="true" />
                                Guardar borrador
                            </PrimaryButton>
                        </div>
                    )}
                </Card>

                <div className="space-y-4">
                    <AudienceSummary summary={summary} exclusions={options.exclusions} frozen={!draft} />
                    {draft && form.isDirty && <Alert tone="warning">Hay cambios sin guardar: la revisión refleja el último borrador guardado.</Alert>}

                    {(canPrepare || canCancel) && (draft || campaign.status === 'prepared') && (
                        <Card className="space-y-3 p-4">
                            {draft && canPrepare && (
                                <PrimaryButton type="button" className="w-full gap-2" disabled={form.isDirty || summary.eligible === 0} onClick={() => step('prepare', `Se fijarán ${summary.eligible} destinatarios y la campaña dejará de ser editable. No se enviará ningún mensaje.`, 'Preparar campaña')}>
                                    <ClipboardCheck className="size-4" aria-hidden="true" />
                                    Preparar campaña
                                </PrimaryButton>
                            )}
                            {campaign.status === 'prepared' && canPrepare && (
                                <PrimaryButton type="button" className="w-full gap-2" onClick={() => step('simulate', 'Se registrará un ensayo sobre los destinatarios fijados. No sale ningún mensaje y solo puede hacerse una vez.', 'Simular')}>
                                    <FlaskConical className="size-4" aria-hidden="true" />
                                    Simular envío
                                </PrimaryButton>
                            )}
                            <SecondaryButton type="button" className="w-full" disabled title={delivery.reason}>
                                Envío real no disponible
                            </SecondaryButton>
                            {canCancel && (
                                <DangerButton type="button" className="w-full gap-2" onClick={() => step('cancel', 'La campaña quedará cancelada y no podrá reactivarse. Su historial se conserva.', 'Cancelar campaña')}>
                                    <Ban className="size-4" aria-hidden="true" />
                                    Cancelar campaña
                                </DangerButton>
                            )}
                        </Card>
                    )}
                </div>
            </div>

            {recipients && <Recipients campaign={campaign} recipients={recipients} exclusions={options.exclusions} perPageOptions={perPageOptions} />}
        </>
    );
}

Show.layout = (page) => <AppLayout>{page}</AppLayout>;
