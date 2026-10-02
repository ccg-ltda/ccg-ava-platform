import { Head, usePage } from '@inertiajs/react';
import { ShieldCheck, UserCog, Users, UsersRound } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import { roleTone } from '@/config/roles';
import AppLayout from '@/Layouts/AppLayout';

const initials = (name = '') =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

/** Reportes: home page after login. Figures come from the active Workspace only. */
export default function Index({ stats, roleBreakdown, recentUsers }) {
    const { workspace } = usePage().props;
    const total = roleBreakdown.reduce((sum, row) => sum + row.count, 0);

    return (
        <>
            <Head title="Reportes" />

            <PageHeader title="Reportes" description={`Resumen de ${workspace?.name} · ${workspace?.organization}`} />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Usuarios" value={stats.users} hint="Con acceso a este Workspace" icon={Users} tone="blue" />
                <StatCard label="Administradores" value={stats.admins} hint="Rol admin" icon={ShieldCheck} tone="violet" delay={60} />
                <StatCard label="Roles en uso" value={stats.rolesInUse} hint="Distintos roles asignados" icon={UsersRound} tone="blue" delay={120} />
                <StatCard label="Tus permisos" value={stats.permissions} hint="Según tu rol" icon={UserCog} tone="green" delay={180} />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card delay={120} className="p-6">
                    <h2 className="text-sm font-bold uppercase tracking-wider text-ink">Usuarios por rol</h2>

                    {roleBreakdown.length === 0 ? (
                        <EmptyState
                            className="mt-4"
                            icon={UsersRound}
                            title="Todavía no hay usuarios"
                            description="Cuando se asignen usuarios a este Workspace verás aquí su distribución por rol."
                        />
                    ) : (
                        <ul className="mt-4 space-y-4">
                            {roleBreakdown.map((row) => (
                                <li key={row.role}>
                                    <div className="flex items-center justify-between text-sm">
                                        <Badge tone={roleTone(row.role)}>{row.role}</Badge>
                                        <span className="font-semibold text-ink">{row.count}</span>
                                    </div>
                                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-canvas">
                                        <div
                                            className="h-full rounded-full bg-accent-blue transition-[width] duration-300"
                                            style={{ width: `${(row.count / total) * 100}%` }}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card delay={180} className="p-6">
                    <h2 className="text-sm font-bold uppercase tracking-wider text-ink">Usuarios recientes</h2>

                    {recentUsers.length === 0 ? (
                        <EmptyState
                            className="mt-4"
                            icon={Users}
                            title="Sin usuarios recientes"
                            description="Los últimos usuarios añadidos al Workspace aparecerán aquí."
                        />
                    ) : (
                        <ul className="mt-4 divide-y divide-line">
                            {recentUsers.map((user) => (
                                <li key={user.id} className="flex items-center gap-3 py-3">
                                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-accent-blue/10 text-xs font-bold text-accent-blue">
                                        {initials(user.name)}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold text-ink">{user.name}</p>
                                        <p className="truncate text-xs text-ink-muted">{user.email}</p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <Badge tone={roleTone(user.role)}>{user.role}</Badge>
                                        <span className="text-[11px] text-ink-muted">{user.createdAt}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
