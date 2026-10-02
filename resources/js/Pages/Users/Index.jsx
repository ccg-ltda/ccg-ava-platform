import { Head } from '@inertiajs/react';
import { KeyRound, ShieldCheck, Users } from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import AppLayout from '@/Layouts/AppLayout';
import PermissionsPanel from './Partials/PermissionsPanel';
import RolesPanel from './Partials/RolesPanel';
import UsersPanel from './Partials/UsersPanel';

const TAB_IDS = ['usuarios', 'roles', 'permisos'];

const initialTab = () => {
    const requested = new URLSearchParams(window.location.search).get('tab');

    return Math.max(TAB_IDS.indexOf(requested), 0);
};

/**
 * Usuarios y Roles: one module, three tabs (only the active one is rendered).
 * The tab lives in the URL (?tab=) so it survives reloads and can be linked.
 */
export default function Index({ users, filters, assignableRoles, roles, permissions, canManageRoles }) {
    const [selected, setSelected] = useState(initialTab);

    const select = (index) => {
        setSelected(index);

        const url = new URL(window.location.href);
        url.searchParams.set('tab', TAB_IDS[index]);
        window.history.replaceState(window.history.state, '', url);
    };

    const tabs = [
        { id: 'usuarios', label: 'Usuarios', icon: Users, count: filters.search ? undefined : users.meta.total },
        { id: 'roles', label: 'Roles', icon: ShieldCheck, count: roles.length },
        { id: 'permisos', label: 'Permisos', icon: KeyRound, count: permissions.length },
    ];

    return (
        <>
            <Head title="Usuarios y Roles" />

            <PageHeader title="Usuarios y Roles" description="Quién accede a este Workspace, con qué rol y qué puede hacer cada rol." />

            <Tabs tabs={tabs} selectedIndex={selected} onChange={select}>
                <UsersPanel users={users} filters={filters} assignableRoles={assignableRoles} />
                <RolesPanel roles={roles} permissions={permissions} canManage={canManageRoles} />
                <PermissionsPanel roles={roles} permissions={permissions} />
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
