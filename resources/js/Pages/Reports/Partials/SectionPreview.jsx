import { Hourglass } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import MetricCard from '@/Components/MetricCard';
import { metricStyles } from '@/config/reports';

/**
 * A section whose data source does not exist: Ava stores nothing to compute it from. It says so (`reason`), shows its
 * metric with no value and the outline of each chart, and it never draws a number or a series.
 */
export default function SectionPreview({ section, metrics }) {
    const metric = metrics.find((item) => item.key === section.metric);

    return (
        <>
            <Card className="flex flex-wrap items-center gap-4 p-5 sm:p-6">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <section.icon className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-base font-bold text-ink">{section.label}</h2>
                        <Badge tone="neutral">
                            <Hourglass className="me-1 size-3" aria-hidden="true" />
                            Sin fuente de datos
                        </Badge>
                    </div>
                    <p className="mt-1 text-sm text-ink-muted">{section.description}</p>
                    <p className="mt-1 text-xs text-ink-muted">{section.reason}</p>
                </div>
            </Card>

            {metric && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard label={metric.label} hint={metric.hint} value={metric.value} {...metricStyles[metric.key]} />
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                {section.charts.map((chart, index) => (
                    <ChartCard key={chart.title} title={chart.title} description={chart.description} delay={index * 60}>
                        <ChartEmpty type={chart.type} description="Aparecerá aquí cuando haya información disponible." />
                    </ChartCard>
                ))}
            </div>
        </>
    );
}
