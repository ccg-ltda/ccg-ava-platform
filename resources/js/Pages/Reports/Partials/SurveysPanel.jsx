import { ClipboardList, Percent, Send, Smile, Star } from 'lucide-react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import DonutChart from '@/Components/Charts/DonutChart';
import RankingList from '@/Components/Charts/RankingList';
import TimeChart from '@/Components/Charts/TimeChart';
import MetricCard from '@/Components/MetricCard';

const pct = (value) => (value === null || value === undefined ? null : `${value}%`);

function Ranking({ title, description, items, loading }) {
    return (
        <ChartCard title={title} description={description} loading={loading} height={200}>
            {items.length === 0 ? <ChartEmpty type="ranking" title="Sin datos en este periodo" description="Aparece cuando las respuestas están ligadas a este dato." height={200} /> : <RankingList items={items} formatValue={(value) => `${value}`} />}
        </ChartCard>
    );
}

/**
 * Reportes > Encuestas: persisted dispatches and responses reported by the automation engine. Every figure is computed from stored rows; an indicator that has no data
 * says so ("—") instead of showing a zero. Response rate = dispatches answered / dispatches sent. Satisfaction = scored responses in the top 20% of their scale.
 */
export default function SurveysPanel({ section, loading, onRetry }) {
    if (!section) return null;

    if (section.error) {
        return (
            <Alert tone="warning">
                {section.error}{' '}
                <button type="button" onClick={onRetry} className="font-semibold underline underline-offset-2">Reintentar</button>
            </Alert>
        );
    }

    const data = section.data;
    const empty = data.sent === 0 && data.responses === 0;
    const scaleHint = data.scale ? `Escala ${data.scale.min} a ${data.scale.max}` : 'Varias escalas: ver por encuesta';

    return (
        <section aria-label="Encuestas" className="space-y-6">
            {empty && (
                <Alert tone="info">Todavía no hay encuestas registradas en este periodo. Cuando el flujo de n8n o Lava Studio las reporte a Ava, aparecerán aquí; no se muestran cifras inventadas.</Alert>
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="Encuestas enviadas" hint="Envíos del periodo" value={data.sent} icon={Send} tone="blue" />
                <MetricCard label="Respuestas" hint={data.incomplete > 0 ? `${data.incomplete} incompletas` : 'Recibidas en el periodo'} value={data.responses} icon={ClipboardList} tone="amber" delay={60} />
                <MetricCard label="Tasa de respuesta" hint="Envíos con respuesta / enviados" value={data.responseRate} formatValue={pct} icon={Percent} tone="violet" delay={120} />
                <MetricCard label="Calificación promedio" hint={scaleHint} value={data.average} icon={Star} tone="green" delay={180} />
                <MetricCard label="Satisfacción" hint="Respuestas en el 20 % superior de la escala" value={data.satisfaction} formatValue={pct} icon={Smile} tone="green" delay={240} />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <ChartCard title="Respuestas en el tiempo" description="Encuestas contestadas por periodo." loading={loading} height={260}>
                    {data.evolution.length === 0 ? <ChartEmpty type="line" title="Sin respuestas" description="Aún no hay respuestas en este periodo." height={260} /> : <TimeChart points={data.evolution} ariaLabel="Respuestas de encuestas por periodo" />}
                </ChartCard>
                <ChartCard title="Distribución de calificaciones" description="Cuántas respuestas tuvo cada calificación." loading={loading} height={260}>
                    {data.distribution.length === 0 ? <ChartEmpty type="donut" title="Sin calificaciones comparables" description="Necesita respuestas con calificación en una misma escala." height={260} /> : <DonutChart items={data.distribution.map((item) => ({ ...item, tone: 'blue' }))} totalLabel="Respuestas" ariaLabel="Distribución de calificaciones" />}
                </ChartCard>
                <Ranking title="Por sede" description="Respuestas recibidas por sede." items={data.byLocation} loading={loading} />
                <Ranking title="Por servicio" description="Según la reserva asociada." items={data.byService} loading={loading} />
                <Ranking title="Por profesional" description="Según la reserva asociada." items={data.byProfessional} loading={loading} />
                <ChartCard title="Por encuesta" description="Promedio y satisfacción de cada encuesta, en su propia escala." loading={loading} height={200}>
                    {data.bySurvey.length === 0 ? (
                        <ChartEmpty type="ranking" title="Sin datos" description="Aún no hay respuestas con calificación." height={200} />
                    ) : (
                        <ul className="divide-y divide-line text-sm">
                            {data.bySurvey.map((survey) => (
                                <li key={survey.name} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span className="font-semibold text-ink">{survey.name}</span>
                                    <span className="text-ink-muted">{survey.responses} resp. · promedio {survey.average}/{survey.scaleMax} · satisfacción {pct(survey.satisfaction)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </ChartCard>
            </div>

            <Card className="p-5">
                <h3 className="text-base font-bold text-ink">Comentarios recibidos</h3>
                {data.comments.length === 0 ? (
                    <p className="mt-2 text-sm text-ink-muted">No hay comentarios en este periodo.</p>
                ) : (
                    <ul className="mt-3 divide-y divide-line">
                        {data.comments.map((item, index) => (
                            <li key={index} className="py-2 text-sm">
                                <p className="text-ink">{item.comment}</p>
                                <p className="text-xs text-ink-muted">{item.rating !== null ? `Calificación ${item.rating} · ` : ''}{new Date(item.at).toLocaleString()}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </section>
    );
}
