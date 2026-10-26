import { Head, Link, router } from '@inertiajs/react';
import { Contact, Eye, Pencil, Plus, Power, Tag } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import Tabs from '@/Components/Tabs';
import useAccess from '@/Hooks/useAccess';
import useUrlTab from '@/Hooks/useUrlTab';
import usePagedList from '@/Hooks/usePagedList';
import AppLayout from '@/Layouts/AppLayout';
import ConsentBadge from './Partials/ConsentBadge';
import CustomerFormModal from './Partials/CustomerFormModal';
import TagsPanel from './Partials/TagsPanel';

const withAll = (label, map) => [{ value: '', label }, ...Object.entries(map).map(([value, text]) => ({ value, label: text }))];

/** Clientes: the customer records of the active Workspace, with search and filters on the server. */
export default function Index({ customers, filters, perPageOptions, options, total, tagAdmin = null }) {
    const confirm = useConfirm();
    const { permissions } = useAccess();
    // Each operation has its own permission: creating, editing, activating and opening a record's detail are independent.
    const canCreate = permissions.includes('create-customers');
    const canEdit = permissions.includes('edit-customers');
    const canToggle = permissions.includes('activate-customers');
    const canDetail = permissions.includes('view-customer-details');
    const [tab, selectTab] = useUrlTab(['clientes', 'etiquetas']);
    const tabs = [{ id: 'clientes', label: 'Clientes', icon: Contact, count: total }, { id: 'etiquetas', label: 'Etiquetas', icon: Tag, count: tagAdmin?.data.length }];
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);

    const list = usePagedList({
        section: 'customers',
        only: ['customers', 'filters'],
        meta: customers.meta,
        perPageOptions,
        routeName: 'customers.index',
        extra: { search: filters.search || undefined, source: filters.source || undefined, consent: filters.consent || undefined, tag: filters.tag || undefined, status: filters.status || undefined },
    });

    // Debounced search; the server filters inside the active Workspace only.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return undefined;
        }

        const timer = setTimeout(() => list.visit({ search: search || undefined }, { restart: true }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filter = (key) => (value) => list.visit({ [key]: value || undefined }, { restart: true });
    const filtered = Boolean(filters.search || filters.source || filters.consent || filters.tag || filters.status);

    const toggle = async (customer) => {
        const confirmed = await confirm({
            description: customer.isActive
                ? `«${customer.fullName}» dejará de recibir campañas y no se ofrecerá como cliente activo. Su historial se conserva y puede reactivarse.`
                : `«${customer.fullName}» volverá a estar activo.`,
            confirmLabel: customer.isActive ? 'Desactivar' : 'Activar',
        });

        if (confirmed) router.post(route(customer.isActive ? 'customers.deactivate' : 'customers.activate', customer.id), {}, { preserveScroll: true });
    };

    const listPanel = (
    <Card className="overflow-hidden">
        <div className="grid gap-3 border-b border-line p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-5">
            <SearchInput className="sm:col-span-2" value={search} onChange={setSearch} placeholder="Buscar por nombre, teléfono, correo o canal" label="Buscar clientes" />
            <Select aria-label="Origen" value={filters.source ?? ''} onChange={filter('source')} options={withAll('Todos los orígenes', options.sources)} />
            <Select aria-label="Consentimiento" value={filters.consent ?? ''} onChange={filter('consent')} options={withAll('Todo consentimiento', options.consents)} />
            <Select aria-label="Etiqueta" value={filters.tag ?? ''} onChange={filter('tag')} options={[{ value: '', label: 'Todas las etiquetas' }, ...options.tags.map((tag) => ({ value: tag, label: tag }))]} />
        </div>

        {customers.data.length === 0 ? (
            <div className="p-6">
                <EmptyState
                    icon={Contact}
                    title={total === 0 ? 'Aún no hay clientes' : 'Ningún cliente coincide'}
                    description={total === 0 ? 'Crea el primero para guardar sus datos de contacto y su consentimiento.' : 'Prueba con otros filtros o con otra búsqueda.'}
                >
                    {filtered && (
                        <PrimaryButton
                            type="button"
                            onClick={() => {
                                setSearch('');
                                router.get(route('customers.index'));
                            }}
                        >
                            Quitar filtros
                        </PrimaryButton>
                    )}
                </EmptyState>
            </div>
        ) : (
            <TableWrap busy={list.loading}>
                <thead>
                    <tr>
                        <Th>Cliente</Th>
                        <Th>Contacto</Th>
                        <Th>Origen</Th>
                        <Th>Consentimiento</Th>
                        <Th>Etiquetas</Th>
                        <Th className="text-center">Acciones</Th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {customers.data.map((customer) => (
                        <tr key={customer.id} className={customer.isActive ? '' : 'opacity-60'}>
                            <Td>
                                {canDetail ? (
                                    <Link href={route('customers.show', customer.id)} className="font-semibold text-ink hover:text-primary">
                                        {customer.fullName}
                                    </Link>
                                ) : (
                                    <span className="font-semibold text-ink">{customer.fullName}</span>
                                )}
                                {customer.anonymized ? <Badge className="ms-2">Anonimizado</Badge> : !customer.isActive && <Badge className="ms-2">Inactivo</Badge>}
                            </Td>
                            <Td className="text-ink-muted">
                                <p>{customer.phone ?? '—'}</p>
                                <p className="break-all">{customer.email ?? ''}</p>
                            </Td>
                            <Td className="text-ink-muted">{options.sources[customer.source] ?? customer.source}</Td>
                            <Td>
                                <ConsentBadge consent={customer.consent} labels={options.consents} />
                            </Td>
                            <Td>
                                <div className="flex flex-wrap gap-1">
                                    {customer.tags.map((tag) => (
                                        <Badge key={tag} tone="blue">
                                            {tag}
                                        </Badge>
                                    ))}
                                </div>
                            </Td>
                            <Td className="text-center">
                                <div className="inline-flex">
                                    {canDetail && (
                                        <Link href={route('customers.show', customer.id)} aria-label={`Ver ${customer.fullName}`} className="grid size-9 place-items-center rounded-lg text-primary hover:bg-primary-soft">
                                            <Eye className="size-[18px]" aria-hidden="true" />
                                        </Link>
                                    )}
                                    {canEdit && !customer.anonymized && <IconButton icon={Pencil} label="Editar" context={customer.fullName} onClick={() => setEditing(customer)} />}
                                    {canToggle && !customer.anonymized && (
                                        <IconButton icon={Power} label={customer.isActive ? 'Desactivar' : 'Activar'} context={customer.fullName} tone={customer.isActive ? 'danger' : 'primary'} onClick={() => toggle(customer)} />
                                    )}
                                </div>
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </TableWrap>
        )}
        <Pagination {...list.paginationProps} noun="clientes" />
    </Card>
    );

    return (
        <>
            <Head title="Clientes" />

            <PageHeader title="Clientes" description="Las personas con las que habla y trabaja este Workspace: contacto, canales, consentimiento y su historial.">
                {canCreate && (
                    <PrimaryButton type="button" onClick={() => setEditing({})} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo cliente
                    </PrimaryButton>
                )}
            </PageHeader>

            {tagAdmin ? (
                <Tabs tabs={tabs} selectedIndex={tab} onChange={selectTab}>
                    {listPanel}
                    <TagsPanel admin={tagAdmin} />
                </Tabs>
            ) : (
                listPanel
            )}

            {editing && <CustomerFormModal customer={editing} options={options} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
