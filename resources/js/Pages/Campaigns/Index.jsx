import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileText, Megaphone, Pencil, Plus, Power } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Tabs from '@/Components/Tabs';
import { TableWrap, Td, Th } from '@/Components/Table';
import TextInput from '@/Components/TextInput';
import useAccess from '@/Hooks/useAccess';
import useFormatDateTime from '@/Hooks/useFormatDateTime';
import usePagedList from '@/Hooks/usePagedList';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from './Partials/StatusBadge';
import LimitedTextarea from './Partials/LimitedTextarea';
import TemplateModal from './Partials/TemplateModal';

/** A new campaign starts as a draft with a name and a channel; audience and message are completed on its own page. */
function NewCampaignModal({ channels, templates, bodyMax, onClose }) {
    const form = useForm({ name: '', channel: Object.keys(channels)[0], message_template_id: '', body: '', audience: 'all' });
    const { data, setData, errors } = form;
    const pick = (value) => {
        const template = templates.find((item) => item.id === value);
        setData((previous) => ({ ...previous, message_template_id: value, body: template ? template.body : previous.body }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((values) => ({ ...values, message_template_id: values.message_template_id || null }));
        form.post(route('campaigns.store'), { onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={submit} noValidate>
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">Nueva campaña</h2>
                </header>
                <div className="space-y-4 px-5 py-5 sm:px-8">
                    <div>
                        <InputLabel htmlFor="campaign_name" value="Nombre" />
                        <TextInput id="campaign_name" className="mt-1" value={data.name} maxLength={120} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="campaign_channel" value="Canal" />
                        <Select id="campaign_channel" className="mt-1" value={data.channel} onChange={(value) => setData('channel', value)} options={Object.entries(channels).map(([value, label]) => ({ value, label }))} />
                        <InputError message={errors.channel} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="campaign_template" value="Plantilla (opcional)" />
                        <Select id="campaign_template" className="mt-1" value={data.message_template_id} onChange={pick} options={[{ value: '', label: 'Escribir el mensaje después' }, ...templates.map((template) => ({ value: template.id, label: template.name }))]} />
                        <InputError message={errors.message_template_id} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="campaign_body" value="Mensaje inicial" />
                        <LimitedTextarea id="campaign_body" value={data.body} onChange={(value) => setData('body', value)} max={bodyMax} minRows={6} invalid={Boolean(errors.body)} />
                        <p className="mt-1 text-xs text-ink-muted">Podrás ajustarlo y previsualizarlo con variables en la página de la campaña.</p>
                        <InputError message={errors.body} className="mt-1" />
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-line px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        Crear borrador
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function TemplatesTab({ templates, canManage, onEdit, onCreate }) {
    const confirm = useConfirm();

    const toggle = async (template) => {
        const confirmed = await confirm({
            description: template.isActive ? `«${template.name}» dejará de ofrecerse para nuevas campañas. Las campañas ya creadas conservan su mensaje.` : `«${template.name}» volverá a ofrecerse.`,
            confirmLabel: template.isActive ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(template.isActive ? 'campaigns.templates.deactivate' : 'campaigns.templates.activate', template.id), {}, { preserveScroll: true });
    };

    if (templates.length === 0) {
        return (
            <EmptyState icon={FileText} title="Aún no hay plantillas" description="Una plantilla es un mensaje reutilizable con variables como {{nombre}}.">
                {canManage && <PrimaryButton type="button" onClick={onCreate}>Crear plantilla</PrimaryButton>}
            </EmptyState>
        );
    }

    return (
        <Card className="overflow-hidden">
            <TableWrap>
                <thead>
                    <tr>
                        <Th>Plantilla</Th>
                        <Th>Mensaje</Th>
                        <Th>Categoría</Th>
                        {canManage && <Th className="text-center">Acciones</Th>}
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {templates.map((template) => (
                        <tr key={template.id} className={template.isActive ? '' : 'opacity-60'}>
                            <Td className="font-semibold text-ink">
                                {template.name}
                                {!template.isActive && <Badge className="ms-2">Inactiva</Badge>}
                            </Td>
                            <Td className="max-w-md text-ink-muted"><p className="line-clamp-2 break-words">{template.body}</p></Td>
                            <Td className="text-ink-muted">{template.category ?? '—'}</Td>
                            {canManage && (
                                <Td className="text-center">
                                    <div className="inline-flex">
                                        <IconButton icon={Pencil} label="Editar" context={template.name} onClick={() => onEdit(template)} />
                                        <IconButton icon={Power} label={template.isActive ? 'Desactivar' : 'Activar'} context={template.name} tone={template.isActive ? 'danger' : 'primary'} onClick={() => toggle(template)} />
                                    </div>
                                </Td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </TableWrap>
        </Card>
    );
}

function CampaignsTab({ campaigns, perPageOptions, channels, statuses, canManage, onCreate }) {
    const formatDateTime = useFormatDateTime();
    const list = usePagedList({ tab: 'campaigns', section: 'campaigns', only: ['campaigns'], meta: campaigns.meta, perPageOptions, routeName: 'campaigns.index' });

    if (campaigns.data.length === 0) {
        return (
            <EmptyState icon={Megaphone} title="Aún no hay campañas" description="Crea una campaña, elige a quién va dirigida y revisa los destinatarios antes de confirmar.">
                {canManage && <PrimaryButton type="button" onClick={onCreate}>Crear campaña</PrimaryButton>}
            </EmptyState>
        );
    }

    return (
        <Card className="overflow-hidden">
            <TableWrap busy={list.loading}>
                <thead>
                    <tr>
                        <Th>Campaña</Th>
                        <Th>Canal</Th>
                        <Th>Estado</Th>
                        <Th>Destinatarios</Th>
                        <Th>Creada</Th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {campaigns.data.map((campaign) => (
                        <tr key={campaign.id}>
                            <Td>
                                <Link href={route('campaigns.show', campaign.id)} className="font-semibold text-ink hover:text-primary">
                                    {campaign.name}
                                </Link>
                            </Td>
                            <Td className="text-ink-muted">{channels[campaign.channel] ?? campaign.channel}</Td>
                            <Td>
                                <StatusBadge status={campaign.status} labels={statuses} />
                            </Td>
                            <Td className="text-ink-muted">{campaign.recipients ?? 'Sin preparar'}</Td>
                            <Td className="text-ink-muted">{formatDateTime(campaign.createdAt)}</Td>
                        </tr>
                    ))}
                </tbody>
            </TableWrap>
            <Pagination {...list.paginationProps} noun="campañas" />
        </Card>
    );
}

/** Campañas: reusable templates and the campaigns addressed to customers. Nothing is sent from here (see the notice). */
export default function Index({ templates, campaigns, perPageOptions, variables, availability, sample, channels, statuses, bodyMax }) {
    const { permissions } = useAccess();
    const canManage = permissions.includes('manage-campaigns');
    const [tab, selectTab] = useUrlTab(['templates', 'campaigns']);
    const [editingTemplate, setEditingTemplate] = useState(null);
    const [creatingCampaign, setCreatingCampaign] = useState(false);
    const activeTemplates = templates.filter((template) => template.isActive);

    return (
        <>
            <Head title="Campañas" />

            <PageHeader title="Campañas" description="Mensajes reutilizables y campañas dirigidas a tus clientes, solo a quienes autorizaron recibirlos.">
                {canManage &&
                    (tab === 0 ? (
                        <PrimaryButton type="button" className="gap-2" onClick={() => setEditingTemplate({})}>
                            <Plus className="size-4" aria-hidden="true" />
                            Nueva plantilla
                        </PrimaryButton>
                    ) : (
                        <PrimaryButton type="button" className="gap-2" onClick={() => setCreatingCampaign(true)}>
                            <Plus className="size-4" aria-hidden="true" />
                            Nueva campaña
                        </PrimaryButton>
                    ))}
            </PageHeader>

            <Tabs
                selectedIndex={tab}
                onChange={selectTab}
                tabs={[
                    { id: 'templates', label: 'Plantillas', icon: FileText, count: templates.length },
                    { id: 'campaigns', label: 'Campañas', icon: Megaphone, count: campaigns.meta.total },
                ]}
            >
                <TemplatesTab templates={templates} canManage={canManage} onEdit={setEditingTemplate} onCreate={() => setEditingTemplate({})} />
                <CampaignsTab campaigns={campaigns} perPageOptions={perPageOptions} channels={channels} statuses={statuses} canManage={canManage} onCreate={() => setCreatingCampaign(true)} />
            </Tabs>

            {editingTemplate && <TemplateModal template={editingTemplate} variables={variables} availability={availability} sample={sample} bodyMax={bodyMax} onClose={() => setEditingTemplate(null)} />}
            {creatingCampaign && <NewCampaignModal channels={channels} templates={activeTemplates} bodyMax={bodyMax} onClose={() => setCreatingCampaign(false)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
