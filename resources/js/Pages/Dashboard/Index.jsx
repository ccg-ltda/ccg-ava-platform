import { Head, router, usePage } from '@inertiajs/react';
import { Bot, CalendarRange, MessageSquareText, MessagesSquare, Sparkles, UserRoundCheck, Users } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import DonutChart from '@/Components/Charts/DonutChart';
import RankingList from '@/Components/Charts/RankingList';
import SeriesChart from '@/Components/Charts/SeriesChart';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import Select from '@/Components/Select';
import WorkspaceCombobox, { ALL_WORKSPACES } from '@/Components/WorkspaceCombobox';
import { activitySeries, stateTones } from '@/config/dashboard';
import AppLayout from '@/Layouts/AppLayout';
import KpiCard from './Partials/KpiCard';
import { AttentionPanel, IntegrationsPanel, QuickLinks, RecentActivity, RecentConversations } from './Partials/Panels';

const retry = () => router.reload({ preserveScroll: true });

/**
 * Dashboard: the executive and operational summary of the Workspace (the detailed analytics are Reportes). Everything shown
 * is computed by the server from the stored data; the period and the Workspace (for platform administrators) are real
 * filters that reload it. Every section arrives on its own, so one that could not be read shows an error and never a zero,
 * and a real zero shows an explicit empty state.
 */
export default function Index({ filters, range, options, scope, can, kpis, activity, states, channels, attention, integrations, recentConversations, recentActivity, demo }) {
    const { workspace, auth, errors } = usePage().props;
    const [loading, setLoading] = useState(false);

    const workspaceParam = scope.mode === 'all' ? 'all' : scope.mode === 'workspace' ? String(scope.workspace.id) : undefined;
    const selectedWorkspace = scope.mode === 'all' ? ALL_WORKSPACES : scope.workspace;
    const scopeLabel = scope.mode === 'all' ? 'todos los Workspaces' : (scope.workspace?.name ?? workspace?.name);
    const foreign = scope.mode !== 'active';

    const visit = (params) =>
        router.get(route('dashboard'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    const changePeriod = (period) => visit({ period, workspace: workspaceParam });
    const changeWorkspace = (option) => option && visit({ period: filters.period, workspace: option.id });

    const k = kpis.data;
    const noActivity = activity.data && activity.data.total === 0;
    const statesTotal = (states.data ?? []).reduce((sum, item) => sum + item.value, 0);

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader title="Dashboard" description={`Resumen de ${scopeLabel} · ${range.from} – ${range.to}`}>
                {scope.canChoose && (
                    <div className="w-full sm:w-72">
                        <InputLabel htmlFor="dashboard_workspace" value="Workspace" className="sr-only" />
                        <WorkspaceCombobox id="dashboard_workspace" purpose="view" includeAll value={selectedWorkspace} onChange={changeWorkspace} placeholder="Buscar Workspace" />
                    </div>
                )}
                <div className="flex items-center gap-2">
                    <CalendarRange className="hidden size-4 text-ink-muted sm:block" aria-hidden="true" />
                    <InputLabel htmlFor="dashboard_period" value="Periodo" className="sr-only" />
                    <Select id="dashboard_period" className="w-52" value={filters.period} onChange={changePeriod} options={options.periods} />
                </div>
            </PageHeader>

            {(errors?.period || errors?.workspace) && <Alert tone="warning">{errors.period ?? errors.workspace}</Alert>}

            {demo?.data > 0 && <Alert tone="info">Estas cifras incluyen {demo.data.toLocaleString('es')} conversaciones de demostración (simuladas), marcadas como tales en la bandeja.</Alert>}

            <div className={`space-y-6 transition-opacity duration-200 ${loading ? 'opacity-60' : ''}`} aria-busy={loading || undefined}>
                {kpis.error ? (
                    <Alert tone="warning">
                        {kpis.error}{' '}
                        <button type="button" onClick={retry} className="font-semibold underline underline-offset-2">
                            Reintentar
                        </button>
                    </Alert>
                ) : (
                    <section aria-label="Indicadores principales" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <KpiCard label="Conversaciones nuevas" value={k.conversations.value} change={k.conversations.change} previous={k.conversations.previous} icon={MessagesSquare} tone="blue" hint={`En el periodo (${range.days} días)`} href={can.conversations ? route('conversations.index') : undefined} />
                        <KpiCard label="Mensajes" value={k.messages.value} change={k.messages.change} previous={k.messages.previous} icon={MessageSquareText} tone="violet" hint={`${k.messagesIn.toLocaleString('es')} recibidos de contactos`} delay={60} />
                        <KpiCard
                            label="Respuestas de la IA"
                            value={k.byAi + k.byAgents > 0 ? Math.round((k.byAi / (k.byAi + k.byAgents)) * 100) : 0}
                            format={(number) => `${number}%`}
                            emptyLabel={k.byAi + k.byAgents === 0 ? 'Sin respuestas todavía' : undefined}
                            icon={Sparkles}
                            tone="green"
                            hint={`${k.byAi.toLocaleString('es')} de ${(k.byAi + k.byAgents).toLocaleString('es')} respuestas de IA o agentes`}
                            delay={90}
                        />
                        <KpiCard label="Conversaciones abiertas" value={k.open} icon={UserRoundCheck} tone="green" hint={`${k.pending.toLocaleString('es')} esperando a un agente`} href={can.conversations ? route('conversations.index') : undefined} delay={120} />
                        <KpiCard label="Asistentes activos" value={k.assistants.active} icon={Bot} tone="blue" hint={`${k.assistants.total.toLocaleString('es')} en total`} href={can.assistants ? route('chatbots.index') : undefined} delay={180} />
                        <KpiCard label="Usuarios" value={k.users} icon={Users} tone="amber" hint={scope.mode === 'all' ? 'En todos los Workspaces' : 'Con acceso al Workspace'} delay={240} />
                    </section>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <ChartCard className="lg:col-span-2" title="Actividad en el tiempo" description={`Por ${{ day: 'día', week: 'semana', month: 'mes' }[range.granularity]}. Pulsa una serie de la leyenda para ocultarla.`} loading={loading} error={activity.error} onRetry={retry} height={300}>
                        {noActivity ? (
                            <ChartEmpty type="line" title="Sin actividad en este periodo" description="No hubo conversaciones ni mensajes. Prueba con un periodo más largo." height={300} />
                        ) : (
                            <SeriesChart key={`${filters.period}-${workspaceParam}`} points={activity.data?.points ?? []} series={activitySeries} ariaLabel="Conversaciones nuevas, mensajes recibidos y respuestas enviadas a lo largo del periodo" />
                        )}
                    </ChartCard>

                    <ChartCard title="Estado de las conversaciones" description="Con actividad en el periodo, como están ahora." loading={loading} error={states.error} onRetry={retry} height={300}>
                        {statesTotal === 0 ? (
                            <ChartEmpty type="donut" title="Sin conversaciones con actividad" description="Cuando haya conversaciones en el periodo verás quién las atiende." height={300} />
                        ) : (
                            <DonutChart
                                items={(states.data ?? []).map((item) => ({ ...item, label: item.label, tone: stateTones[item.key] }))}
                                totalLabel="Total"
                                ariaLabel="Distribución de las conversaciones por quién las atiende"
                            />
                        )}
                    </ChartCard>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <ChartCard title="Canales" description="Conversaciones nuevas por canal." loading={loading} error={channels.error} onRetry={retry} height={140}>
                        {(channels.data ?? []).length === 0 ? <ChartEmpty type="ranking" title="Sin conversaciones nuevas" description="Aquí verás desde qué canal escriben tus contactos." height={140} /> : <RankingList items={channels.data} share />}
                    </ChartCard>

                    {attention && <AttentionPanel section={attention} loading={loading} />}
                    {integrations && <IntegrationsPanel section={integrations} loading={loading} />}
                </div>

                {(recentConversations || recentActivity) && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {recentConversations && <RecentConversations section={recentConversations} loading={loading} foreign={foreign} />}
                        {recentActivity && <RecentActivity section={recentActivity} loading={loading} />}
                    </div>
                )}

                <QuickLinks permissions={auth.user?.permissions} />
            </div>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
