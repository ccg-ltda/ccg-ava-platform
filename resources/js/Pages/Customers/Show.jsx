import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Eraser, Megaphone, MessagesSquare, Pencil } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import DangerButton from '@/Components/DangerButton';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import SecondaryButton from '@/Components/SecondaryButton';
import useAccess from '@/Hooks/useAccess';
import AppLayout from '@/Layouts/AppLayout';
import useFormatDateTime from '@/Hooks/useFormatDateTime';
import ConsentBadge from './Partials/ConsentBadge';
import CustomerFormModal from './Partials/CustomerFormModal';

function Fact({ label, children }) {
    return (
        <div className="min-w-0">
            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</dt>
            <dd className="mt-1 text-sm break-words text-ink">{children || '—'}</dd>
        </div>
    );
}

/** Detail of one customer: their data, consent and what the Workspace's conversations and agenda hold about them. */
export default function Show({ customer, conversations, appointments, campaigns, options }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    const formatDateTime = useFormatDateTime();
    const [editing, setEditing] = useState(false);
    const anonymize = async () => {
        const confirmed = await confirm({ description: `Se borrarán los datos personales de «${customer.fullName}». No se puede deshacer y no afecta a sus conversaciones ni reservas.`, confirmLabel: 'Anonimizar' });

        if (confirmed) router.post(route('customers.anonymize', customer.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title={customer.fullName} />

            <Link href={route('customers.index')} className="inline-flex items-center gap-2 text-sm font-semibold text-primary hover:underline">
                <ArrowLeft className="size-4" aria-hidden="true" />
                Clientes
            </Link>

            <PageHeader title={customer.fullName} description={options.sources[customer.source] ? `Origen: ${options.sources[customer.source]}` : undefined}>
                {customer.anonymized ? <Badge>Anonimizado</Badge> : !customer.isActive && <Badge>Inactivo</Badge>}
                {permissions.includes('edit-customers') && !customer.anonymized && (
                    <SecondaryButton type="button" className="gap-2" onClick={() => setEditing(true)}>
                        <Pencil className="size-4" aria-hidden="true" />
                        Editar
                    </SecondaryButton>
                )}
            </PageHeader>

            {customer.anonymized && <Alert tone="info">Los datos personales de este cliente fueron eliminados a petición. El registro se conserva sin datos para no romper las conversaciones, reservas y campañas que lo mencionan, y no volverá a recibir campañas.</Alert>}

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="p-5 sm:p-6 lg:col-span-2">
                    <h2 className="text-base font-bold text-ink">Datos</h2>
                    <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                        <Fact label="Teléfono">{customer.phone}</Fact>
                        <Fact label="Correo">{customer.email}</Fact>
                        <Fact label="Canal preferido">{options.channels[customer.preferredChannel]}</Fact>
                        <Fact label="Alta">{formatDateTime(customer.createdAt)}</Fact>
                        <Fact label="Canales e identificadores">
                            {customer.identities.length > 0 && (
                                <ul className="space-y-0.5">
                                    {customer.identities.map((identity) => (
                                        <li key={`${identity.channel}-${identity.external_id}`}>
                                            {options.channels[identity.channel] ?? identity.channel}: {identity.external_id}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Fact>
                        <Fact label="Etiquetas">
                            {customer.tags.length > 0 && (
                                <span className="flex flex-wrap gap-1">
                                    {customer.tags.map((tag) => (
                                        <Badge key={tag} tone="blue">
                                            {tag}
                                        </Badge>
                                    ))}
                                </span>
                            )}
                        </Fact>
                    </dl>
                    {customer.notes && (
                        <div className="mt-5 border-t border-line pt-4">
                            <h3 className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Notas</h3>
                            <p className="mt-1 text-sm whitespace-pre-line text-ink">{customer.notes}</p>
                        </div>
                    )}
                </Card>

                <Card className="p-5 sm:p-6">
                    <h2 className="text-base font-bold text-ink">Consentimiento</h2>
                    <div className="mt-4 space-y-3 text-sm">
                        <ConsentBadge consent={customer.consent} labels={options.consents} />
                        {customer.consentAt && <p className="text-ink-muted">Registrado el {formatDateTime(customer.consentAt)}</p>}
                        {customer.consentNote && <p className="text-ink">{customer.consentNote}</p>}
                        {customer.consent !== 'granted' && <Alert tone="info">Este cliente no recibe campañas hasta que se registre su autorización.</Alert>}
                    </div>
                </Card>
            </div>

            {conversations !== null && (
                <Card className="p-5 sm:p-6">
                    <h2 className="flex items-center gap-2 text-base font-bold text-ink">
                        <MessagesSquare className="size-4" aria-hidden="true" />
                        Conversaciones
                    </h2>
                    {conversations.length === 0 ? (
                        <EmptyState className="mt-4" title="Sin conversaciones vinculadas" description="Se vinculan con el identificador del cliente en cada canal (editar > Canales e identificadores)." />
                    ) : (
                        <ul className="mt-4 divide-y divide-line text-sm">
                            {conversations.map((conversation) => (
                                <li key={conversation.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                    <span className="font-medium text-ink">{options.channels[conversation.channel] ?? conversation.channel}</span>
                                    <Badge>{conversation.handling}</Badge>
                                    <span className="text-ink-muted">{conversation.lastMessageAt ? formatDateTime(conversation.lastMessageAt) : 'Sin mensajes'}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {appointments !== null && (
                <Card className="p-5 sm:p-6">
                    <h2 className="flex items-center gap-2 text-base font-bold text-ink">
                        <CalendarDays className="size-4" aria-hidden="true" />
                        Reservas
                    </h2>
                    {appointments.length === 0 ? (
                        <EmptyState className="mt-4" title="Sin reservas" description="Se buscan por el mismo teléfono o correo en la agenda." />
                    ) : (
                        <ul className="mt-4 divide-y divide-line text-sm">
                            {appointments.map((appointment) => (
                                <li key={appointment.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                    <span className="font-medium text-ink">
                                        {appointment.service}
                                        {(appointment.location || appointment.price !== null) && (
                                            <span className="block text-xs font-normal text-ink-muted">
                                                {[appointment.location, appointment.price !== null && appointment.currency ? new Intl.NumberFormat('es', { style: 'currency', currency: appointment.currency }).format(appointment.price) : null].filter(Boolean).join(' · ')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-ink-muted">{formatDateTime(appointment.startsAt)}</span>
                                    <Badge>{appointment.status}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {campaigns !== null && (
                <Card className="p-5 sm:p-6">
                    <h2 className="flex items-center gap-2 text-base font-bold text-ink">
                        <Megaphone className="size-4" aria-hidden="true" />
                        Campañas
                    </h2>
                    {campaigns.length === 0 ? (
                        <EmptyState className="mt-4" title="Sin campañas" description="Aquí aparecen las campañas preparadas que incluyeron a este cliente, y si quedó excluido." />
                    ) : (
                        <ul className="mt-4 divide-y divide-line text-sm">
                            {campaigns.map((campaign) => (
                                <li key={campaign.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                    <Link href={route('campaigns.show', campaign.id)} className="font-medium text-ink hover:text-primary">
                                        {campaign.name}
                                    </Link>
                                    <Badge tone={campaign.status === 'excluded' ? 'amber' : 'neutral'}>{campaign.status === 'excluded' ? 'Excluido' : campaign.status === 'simulated' ? 'Simulado' : 'Pendiente'}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}

            {permissions.includes('anonymize-customers') && !customer.anonymized && (
                <Card className="p-5 sm:p-6">
                    <h2 className="text-base font-bold text-ink">Eliminar datos personales</h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        Borra el nombre, el contacto, las notas, las etiquetas y los identificadores de canal de este cliente. No modifica sus conversaciones ni sus reservas, que siguen en sus módulos. No se puede deshacer.
                    </p>
                    <DangerButton type="button" className="mt-3 gap-2" onClick={() => anonymize()}>
                        <Eraser className="size-4" aria-hidden="true" />
                        Anonimizar cliente
                    </DangerButton>
                </Card>
            )}

            {editing && <CustomerFormModal customer={customer} options={options} onClose={() => setEditing(false)} />}
        </>
    );
}

Show.layout = (page) => <AppLayout>{page}</AppLayout>;
