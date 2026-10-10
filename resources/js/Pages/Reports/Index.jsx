import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import InputLabel from '@/Components/InputLabel';
import MetricCard from '@/Components/MetricCard';
import WorkspaceCombobox, { ALL_WORKSPACES } from '@/Components/WorkspaceCombobox';
import PageHeader from '@/Components/PageHeader';
import Select from '@/Components/Select';
import Tabs from '@/Components/Tabs';
import { metricStyles, reportSections } from '@/config/reports';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import SectionPreview from './Partials/SectionPreview';
import TeamPanel from './Partials/TeamPanel';

const SECTION_IDS = reportSections.map((section) => section.id);

/**
 * Reportes: the analytics center of the active Workspace. The period and the grouping are real controls (they reload
 * the server data); the metrics and charts of modules that do not exist yet are prepared spaces that show "Sin datos
 * todavía" and never a number. The only series with real data today is the team of the Workspace.
 */
export default function Index({ stats, roleBreakdown, recentUsers, filters, range, options, metrics, teamGrowth, scope }) {
    const { workspace, errors } = usePage().props;
    const [tab, selectTab] = useUrlTab(SECTION_IDS);
    const [loading, setLoading] = useState(false);

    // What the administrative selector asked for, to keep it across period and grouping changes.
    const workspaceParam = scope.mode === 'all' ? 'all' : scope.mode === 'workspace' ? String(scope.workspace.id) : undefined;
    const selectedWorkspace = scope.mode === 'all' ? ALL_WORKSPACES : scope.workspace;

    const visit = (params, { everything = false } = {}) =>
        router.get(route('reports.index'), { ...params, tab: SECTION_IDS[tab] }, {
            // Period and grouping only change the series; a new scope changes every figure.
            only: everything ? undefined : ['filters', 'range', 'options', 'teamGrowth', 'errors'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    // A new period starts with its own default grouping; a grouping change keeps the period.
    const changePeriod = (period) => visit({ period, workspace: workspaceParam });
    const changeGranularity = (granularity) => visit({ period: filters.period, granularity, workspace: workspaceParam });
    const changeWorkspace = (option) => option && visit({ period: filters.period, workspace: option.id }, { everything: true });

    const scopeLabel = scope.mode === 'all' ? 'todos los Workspaces' : (scope.workspace?.name ?? workspace?.name);

    const summaryMetrics = metrics.map((metric, index) => <MetricCard key={metric.key} label={metric.label} hint={metric.hint} value={metric.value} delay={index * 60} {...metricStyles[metric.key]} />);

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

            <Tabs
                tabs={reportSections.map(({ id, label, icon }) => ({ id, label, icon }))}
                selectedIndex={tab}
                onChange={selectTab}
            >
                {reportSections.map((section) =>
                    section.id === 'summary' ? (
                        <div key={section.id} className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{summaryMetrics}</div>

                            <div className="grid gap-6 lg:grid-cols-3">
                                <ChartCard className="lg:col-span-2" title="Actividad en el tiempo" description="Chats, interacciones y preguntas por periodo.">
                                    <ChartEmpty type="line" description="Cuando Ava registre conversaciones verás aquí su evolución día a día, semana a semana o mes a mes." />
                                </ChartCard>
                                <ChartCard title="Preguntas frecuentes" description="Lo que más se consulta.">
                                    <ChartEmpty type="ranking" description="El ranking aparecerá cuando haya preguntas registradas." />
                                </ChartCard>
                            </div>

                            <TeamPanel
                                stats={stats}
                                roleBreakdown={roleBreakdown}
                                recentUsers={recentUsers}
                                teamGrowth={teamGrowth}
                                filters={filters}
                                range={range}
                                options={options}
                                loading={loading}
                                onGranularity={changeGranularity}
                                global={scope.mode === 'all'}
                            />
                        </div>
                    ) : (
                        <div key={section.id} className="space-y-6">
                            <SectionPreview section={section} metrics={metrics} />
                        </div>
                    ),
                )}
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
