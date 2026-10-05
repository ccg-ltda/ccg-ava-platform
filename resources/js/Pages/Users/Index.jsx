import { Head } from '@inertiajs/react';
import { Building, Building2, KeyRound, ShieldCheck, Users } from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import OrganizationsPanel from './Partials/OrganizationsPanel';
import PermissionsPanel from './Partials/PermissionsPanel';
import RolesPanel from './Partials/RolesPanel';
import UsersPanel from './Partials/UsersPanel';
import WorkspacesPanel from './Partials/WorkspacesPanel';

/**
 * Usuarios y Roles: one module, up to five tabs (only the active one is rendered). Organizaciones only
 * exists for superusers. The tab lives in the URL (?tab=) so it survives reloads and can be linked;
 * every list pages independently on the server (see usePagedList).
 */
export default function Index({
    users,
    filters,
    perPageOptions,
    createTargets,
    roles,
    permissions,
    permissionNames,
    permissionRoles,
    canManageRoles,
    workspaces,
    organizations,
}) {
    const sections = [
        {
            id: 'usuarios',
            label: 'Usuarios',
            icon: Users,
            count: filters.search || filters.status !== 'all' ? undefined : users.meta.total,
            panel: <UsersPanel users={users} filters={filters} perPageOptions={perPageOptions} createTargets={createTargets} />,
        },
        {
            id: 'roles',
            label: 'Roles',
            icon: ShieldCheck,
            count: roles.meta.total,
            panel: <RolesPanel roles={roles} permissionNames={permissionNames} perPageOptions={perPageOptions} canManage={canManageRoles} />,
        },
        {
            id: 'permisos',
            label: 'Permisos',
            icon: KeyRound,
            count: permissions.meta.total,
            panel: <PermissionsPanel roles={permissionRoles} permissions={permissions} perPageOptions={perPageOptions} />,
        },
        {
            id: 'workspaces',
            label: 'Workspaces',
            icon: Building2,
            count: workspaces.list.meta.total,
            panel: <WorkspacesPanel workspaces={workspaces} perPageOptions={perPageOptions} />,
        },
        organizations && {
            id: 'organizaciones',
            label: 'Organizaciones',
            icon: Building,
            count: organizations.meta.total,
            panel: <OrganizationsPanel organizations={organizations} perPageOptions={perPageOptions} />,
        },
    ].filter(Boolean);

    const [selected, select] = useUrlTab(sections.map((section) => section.id));

    return (
        <>
            <Head title="Usuarios y Roles" />

            <PageHeader title="Usuarios y Roles" description="Quién accede, con qué rol, qué puede hacer cada rol y cómo se organizan los Workspaces." />

            <Tabs tabs={sections} selectedIndex={selected} onChange={select}>
                {sections.map((section) => section.panel)}
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
