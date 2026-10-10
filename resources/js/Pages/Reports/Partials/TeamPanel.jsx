import { UserPlus, Users } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import RankingList from '@/Components/Charts/RankingList';
import TimeChart from '@/Components/Charts/TimeChart';
import EmptyState from '@/Components/EmptyState';
import { roleTone } from '@/config/roles';

const initials = (name = '') =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

/** Segmented control: the options the server allows for the current period. */
function Granularity({ options, value, onChange }) {
    if (options.length < 2) return null;

    return (
        <div role="group" aria-label="Agrupar por" className="inline-flex rounded-lg border border-field bg-card p-0.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={option.value === value}
                    onClick={() => onChange(option.value)}
                    className={`rounded-md px-3 py-1.5 text-xs font-semibold tracking-wider uppercase transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary ${
                        option.value === value ? 'bg-primary text-white' : 'text-ink-muted hover:text-ink'
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

/**
 * The part of Reportes that already has real data: the people of this Workspace. Its figures and the "altas" chart
 * come from the memberships and follow the selected period and granularity.
 */
export default function TeamPanel({ stats, roleBreakdown, recentUsers, teamGrowth, filters, range, options, loading, onGranularity, global = false }) {
    const figures = [
        ['Usuarios', stats.users],
        ['Administradores', stats.admins],
        ['Roles en uso', stats.rolesInUse],
        ['Tus permisos', stats.permissions],
    ];

    return (
        <section aria-labelledby="team-heading" className="space-y-6">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="team-heading" className="text-lg font-bold text-ink">
                        {global ? 'Equipo de la plataforma' : 'Equipo del Workspace'}
                    </h2>
                    <p className="text-sm text-ink-muted">{global ? 'Personas con acceso a cualquier Workspace, sin contar dos veces a quien está en varios.' : 'Las personas con acceso, a partir de los datos que ya existen.'}</p>
                </div>
                <dl className="flex flex-wrap gap-x-6 gap-y-2">
                    {figures.map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</dt>
                            <dd className="text-xl font-extrabold text-ink">{value}</dd>
                        </div>
                    ))}
                </dl>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <ChartCard
                    className="lg:col-span-2"
                    title="Altas de usuarios"
                    description={`${global ? 'Altas en la plataforma' : 'Personas añadidas al Workspace'} · ${range.from} – ${range.to}`}
                    loading={loading}
                    actions={<Granularity options={options.granularities} value={filters.granularity} onChange={onGranularity} />}
                >
                    {teamGrowth.total > 0 ? (
                        <TimeChart variant="bars" points={teamGrowth.points} valueLabel={teamGrowth.total === 1 ? 'alta' : 'altas'} ariaLabel={`Altas de usuarios por ${filters.granularity}`} />
                    ) : (
                        <ChartEmpty type="bars" icon={UserPlus} title="Sin altas en este periodo" description={`Nadie se unió al Workspace entre ${range.from} y ${range.to}. Prueba con un periodo más amplio.`} />
                    )}
                </ChartCard>

                <ChartCard title="Usuarios por rol" description="Distribución actual de los roles.">
                    {roleBreakdown.length === 0 ? (
                        <ChartEmpty type="ranking" icon={Users} title="Todavía no hay usuarios" height={200} />
                    ) : (
                        <RankingList share items={roleBreakdown.map((row) => ({ key: row.role, label: row.role, value: row.count }))} renderLabel={(item) => <Badge tone={roleTone(item.label)}>{item.label}</Badge>} />
                    )}
                </ChartCard>
            </div>

            {recentUsers !== null && (
            <Card className="p-5 sm:p-6">
                <h3 className="text-sm font-bold tracking-wider text-ink uppercase">Usuarios recientes</h3>

                {recentUsers.length === 0 ? (
                    <EmptyState className="mt-4" icon={Users} title="Sin usuarios recientes" description="Los últimos usuarios añadidos al Workspace aparecerán aquí." />
                ) : (
                    <ul className="mt-4 divide-y divide-line">
                        {recentUsers.map((user) => (
                            <li key={`${user.id}-${user.workspace ?? ''}`} className="flex items-center gap-3 py-3">
                                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-accent-blue/10 text-xs font-bold text-accent-blue">{initials(user.name)}</span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-ink">{user.name}</p>
                                    <p className="truncate text-xs text-ink-muted">{user.workspace ? `${user.email} · ${user.workspace}` : user.email}</p>
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
            )}
        </section>
    );
}
