import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, CalendarRange, Minus } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import DonutChart from '@/Components/Charts/DonutChart';
import RankingList from '@/Components/Charts/RankingList';
import SeriesChart from '@/Components/Charts/SeriesChart';
import TimeChart from '@/Components/Charts/TimeChart';
import InputLabel from '@/Components/InputLabel';
import MetricCard from '@/Components/MetricCard';
import PageHeader from '@/Components/PageHeader';
import Select from '@/Components/Select';
import Tabs from '@/Components/Tabs';
import WorkspaceCombobox, { ALL_WORKSPACES } from '@/Components/WorkspaceCombobox';
import { stateTones } from '@/config/dashboard';
import { metricStyles, reportSections, seriesLabels } from '@/config/reports';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import SectionPreview from './Partials/SectionPreview';
import TeamPanel from './Partials/TeamPanel';

const SECTION_IDS = reportSections.map((section) => section.id);
const GRANULARITY = { day: 'día', week: 'semana', month: 'mes' };

const retry = () => router.reload({ preserveScroll: true });
const percent = (change) => (change === null || change === undefined ? undefined : `${change > 0 ? '+' : ''}${change}%`);

/** Change of a row of the comparison table: a real percentage, or the plain fact that there is nothing to compare with. */
function Variation({ change, previous }) {
    if (change === null) return <span className="text-xs text-ink-muted">{previous === 0 ? 'Sin periodo anterior' : ''}</span>;

    const Icon = change > 0 ? ArrowUpRight : change < 0 ? ArrowDownRight : Minus;
    const color = change > 0 ? 'text-accent-green' : change < 0 ? 'text-danger' : 'text-ink-muted';

    return (
        <span className={`inline-flex items-center gap-0.5 text-xs font-semibold ${color}`}>
            <Icon className="size-3.5" aria-hidden="true" />
            {percent(change)}
        </span>
    );
}

/**
 * Reportes: the detailed analytics of the active Workspace (the executive summary is the Dashboard, which uses the same
 * calculations). The period, the grouping and the Workspace (platform administrators) are real controls that reload the
 * server data. Every section arrives on its own: one that could not be read shows an error and never a zero, and a real
 * zero shows an explicit empty state. Questions and surveys have no data source and say so.
 */
export default function Index({ stats, roleBreakdown, recentUsers, filters, range, options, metrics, teamGrowth, figures, activity, channels, states, chatHours, messageHours, scope }) {
    const { workspace, errors } = usePage().props;
    const [tab, selectTab] = useUrlTab(SECTION_IDS);
    const [loading, setLoading] = useState(false);

    // What the administrative selector asked for, to keep it across period and grouping changes.
    const workspaceParam = scope.mode === 'all' ? 'all' : scope.mode === 'workspace' ? String(scope.workspace.id) : undefined;
    const selectedWorkspace = scope.mode === 'all' ? ALL_WORKSPACES : scope.workspace;

    const visit = (params) =>
        router.get(route('reports.index'), { ...params, tab: SECTION_IDS[tab] }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    // A new period starts with its own default grouping; a grouping change keeps the period.
    const changePeriod = (period) => visit({ period, workspace: workspaceParam });
    const changeGranularity = (granularity) => visit({ period: filters.period, granularity, workspace: workspaceParam });
    const changeWorkspace = (option) => option && visit({ period: filters.period, workspace: option.id });

    const scopeLabel = scope.mode === 'all' ? 'todos los Workspaces' : (scope.workspace?.name ?? workspace?.name);
    const noActivity = activity.data && activity.data.total === 0;
    const statesTotal = (states.data ?? []).reduce((sum, item) => sum + item.value, 0);
    const granularity = GRANULARITY[range.granularity ?? filters.granularity];

    const metricCard = (metric, index) => {
        const figure = metric.connected ? figures.data?.[metric.key] : null;

        return (
            <MetricCard
                key={metric.key}
                label={metric.label}
                hint={!metric.connected ? 'Sin fuente de datos' : figures.error ? 'No se pudo leer' : figure ? (figure.previous > 0 ? `Antes: ${figure.previous.toLocaleString('es')}` : metric.hint) : metric.hint}
                value={figure ? figure.value : null}
                change={figure ? percent(figure.change) : undefined}
                delay={index * 60}
                {...metricStyles[metric.key]}
            />
        );
    };

    const errorAlert = (section) =>
        section.error && (
            <Alert tone="warning">
                {section.error}{' '}
                <button type="button" onClick={retry} className="font-semibold underline underline-offset-2">
                    Reintentar
                </button>
            </Alert>
        );

    const activityChart = (keys, title, description, ariaLabel) => (
        <ChartCard className="lg:col-span-2" title={title} description={description} loading={loading} error={activity.error} onRetry={retry} height={300}>
            {noActivity ? (
                <ChartEmpty type="line" title="Sin actividad en este periodo" description="No hubo conversaciones ni mensajes. Prueba con un periodo más largo." height={300} />
            ) : (
                <SeriesChart key={`${keys.join('-')}-${filters.period}-${filters.granularity}-${workspaceParam}`} points={activity.data?.points ?? []} series={keys.map((key) => seriesLabels[key])} ariaLabel={ariaLabel} />
            )}
        </ChartCard>
    );

    const hoursChart = (section, title, description, valueLabel) => (
        <ChartCard title={title} description={description} loading={loading} error={section.error} onRetry={retry} height={300}>
            {(section.data?.total ?? 0) === 0 ? (
                <ChartEmpty type="bars" title="Sin actividad en este periodo" description="Aquí verás en qué horas del día se concentra." height={300} />
            ) : (
                <TimeChart variant="bars" points={section.data.points} valueLabel={valueLabel} ariaLabel={`${title} por hora del día`} />
            )}
        </ChartCard>
    );

    const channelsChart = (
        <ChartCard title="Canales" description="Conversaciones nuevas por canal." loading={loading} error={channels.error} onRetry={retry} height={140}>
            {(channels.data ?? []).length === 0 ? <ChartEmpty type="ranking" title="Sin conversaciones nuevas" description="Aquí verás desde qué canal escriben tus contactos." height={140} /> : <RankingList items={channels.data} share />}
        </ChartCard>
    );

    const statesChart = (
        <ChartCard title="Estado de las conversaciones" description="Con actividad en el periodo, como están ahora." loading={loading} error={states.error} onRetry={retry} height={300}>
            {statesTotal === 0 ? (
                <ChartEmpty type="donut" title="Sin conversaciones con actividad" description="Cuando haya conversaciones en el periodo verás quién las atiende." height={300} />
            ) : (
                <DonutChart items={(states.data ?? []).map((item) => ({ ...item, tone: stateTones[item.key] }))} totalLabel="Total" ariaLabel="Distribución de las conversaciones por quién las atiende" />
            )}
        </ChartCard>
    );

    const sectionBody = (section) => {
        const byKey = Object.fromEntries(metrics.map((metric, index) => [metric.key, [metric, index]]));
        const one = (key) => metricCard(...byKey[key]);

        switch (section.id) {
            case 'summary':
                return (
                    <div className="space-y-6">
                        {errorAlert(figures)}
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{metrics.map(metricCard)}</div>

                        <div className="grid gap-6 lg:grid-cols-3">
                            {activityChart(['conversations', 'received', 'sent'], 'Actividad en el tiempo', `Por ${granularity}. Pulsa una serie de la leyenda para ocultarla.`, 'Conversaciones nuevas, mensajes recibidos y respuestas enviadas a lo largo del periodo')}
                            {channelsChart}
                        </div>

                        <TeamPanel stats={stats} roleBreakdown={roleBreakdown} recentUsers={recentUsers} teamGrowth={teamGrowth} filters={filters} range={range} options={options} loading={loading} onGranularity={changeGranularity} global={scope.mode === 'all'} />
                    </div>
                );
            case 'conversations':
                return (
                    <div className="space-y-6">
                        {errorAlert(figures)}
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{one('chats')}</div>
                        <div className="grid gap-6 lg:grid-cols-3">
                            {activityChart(['conversations'], 'Chats en el tiempo', `Conversaciones iniciadas por ${granularity}.`, 'Conversaciones nuevas a lo largo del periodo')}
                            {statesChart}
                        </div>
                        <div className="grid gap-6 lg:grid-cols-2">
                            {hoursChart(chatHours, 'Chats por franja horaria', 'Hora local del Workspace en que empiezan las conversaciones.', 'chats')}
                            {channelsChart}
                        </div>
                    </div>
                );
            case 'interactions':
                return (
                    <div className="space-y-6">
                        {errorAlert(figures)}
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{one('interactions')}</div>
                        <div className="grid gap-6 lg:grid-cols-3">
                            {activityChart(['received', 'sent'], 'Interacciones en el tiempo', `Mensajes recibidos y respuestas enviadas por ${granularity}.`, 'Mensajes recibidos y respuestas enviadas a lo largo del periodo')}
                            {channelsChart}
                        </div>
                        {hoursChart(messageHours, 'Mensajes recibidos por franja horaria', 'Hora local del Workspace en que escriben los contactos.', 'mensajes')}
                    </div>
                );
            case 'trends':
                return (
                    <div className="space-y-6">
                        {errorAlert(figures)}
                        <Card className="overflow-hidden">
                            <div className="p-5 sm:p-6">
                                <h2 className="text-base font-bold text-ink">Frente al periodo anterior</h2>
                                <p className="mt-1 text-sm text-ink-muted">Cada cifra se compara con los {range.days} días inmediatamente anteriores a {range.from}.</p>
                            </div>
                            {figures.data ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <caption className="sr-only">Cifras del periodo frente al periodo anterior</caption>
                                        <thead className="border-y border-line bg-canvas/60 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">
                                            <tr>
                                                <th scope="col" className="px-5 py-3 sm:px-6">Medida</th>
                                                <th scope="col" className="px-4 py-3 text-right">Periodo</th>
                                                <th scope="col" className="px-4 py-3 text-right">Anterior</th>
                                                <th scope="col" className="px-5 py-3 text-right sm:px-6">Variación</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-line">
                                            {figures.data.rows.map((row) => (
                                                <tr key={row.key}>
                                                    <th scope="row" className="px-5 py-3 font-medium text-ink sm:px-6">{row.label}</th>
                                                    <td className="px-4 py-3 text-right font-semibold text-ink tabular-nums">{row.value.toLocaleString('es')}</td>
                                                    <td className="px-4 py-3 text-right text-ink-muted tabular-nums">{row.previous.toLocaleString('es')}</td>
                                                    <td className="px-5 py-3 text-right sm:px-6">
                                                        <Variation change={row.change} previous={row.previous} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : null}
                        </Card>
                        <div className="grid gap-6 lg:grid-cols-3">
                            {activityChart(['conversations', 'received', 'sent'], 'Evolución comparada', `Las tres medidas sobre la misma línea de tiempo, por ${granularity}.`, 'Conversaciones nuevas, mensajes recibidos y respuestas enviadas a lo largo del periodo')}
                            {statesChart}
                        </div>
                    </div>
                );
            default:
                return <SectionPreview section={section} metrics={metrics} />;
        }
    };

    return (
        <>
            <Head title="Reportes" />

            <PageHeader title="Reportes" description={`Analítica de ${scopeLabel} · ${range.from} – ${range.to}`}>
                {scope.canChoose && (
                    <div className="w-full sm:w-72">
                        <InputLabel htmlFor="report_workspace" value="Workspace" className="sr-only" />
                        <WorkspaceCombobox id="report_workspace" purpose="view" includeAll value={selectedWorkspace} onChange={changeWorkspace} placeholder="Buscar Workspace" />
                    </div>
                )}
                <div className="flex items-center gap-2">
                    <CalendarRange className="hidden size-4 text-ink-muted sm:block" aria-hidden="true" />
                    <InputLabel htmlFor="report_period" value="Periodo" className="sr-only" />
                    <Select id="report_period" className="w-52" value={filters.period} onChange={changePeriod} options={options.periods} />
                </div>
            </PageHeader>

            {(errors?.period || errors?.granularity) && <Alert tone="warning">{errors.period ?? errors.granularity}</Alert>}

            <Tabs tabs={reportSections.map(({ id, label, icon }) => ({ id, label, icon }))} selectedIndex={tab} onChange={selectTab}>
                {reportSections.map((section) => (
                    <div key={section.id} className={`space-y-6 transition-opacity duration-200 ${loading ? 'opacity-60' : ''}`} aria-busy={loading || undefined}>
                        {sectionBody(section)}
                    </div>
                ))}
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
