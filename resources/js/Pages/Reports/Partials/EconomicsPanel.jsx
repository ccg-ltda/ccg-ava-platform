import { ArrowDownRight, ArrowUpRight, CalendarCheck, Coins, Minus, PackageCheck, ReceiptText, TrendingUp } from 'lucide-react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import SeriesChart from '@/Components/Charts/SeriesChart';
import MetricCard from '@/Components/MetricCard';
import { formatMoney } from '@/lib/format';

/** Short money for the axis («RD$ 200 mil», «RD$ 1,5 M»): the full amount stays in the tooltip and the cards. */
const compact = (value, currency) => new Intl.NumberFormat('es', { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(value);

const percent = (change) => (change === null || change === undefined ? undefined : `${change > 0 ? '+' : ''}${change}%`);

const MODULES = {
    bookings: {
        title: 'Reservas',
        icon: CalendarCheck,
        incomeLabel: 'Ingreso por servicios realizados',
        incomeHint: 'Precio registrado de las citas marcadas como realizadas',
        countLabel: 'Citas realizadas',
        countHint: 'Con precio registrado, las que generan el ingreso',
        averageLabel: 'Valor promedio por cita',
        chartTitle: 'Ingresos por servicios realizados',
        basis: 'Cada cita cuenta el día en que se realizó. Una cita pendiente, confirmada, cancelada o que no asistió no suma nada.',
        empty: 'No hubo citas realizadas con precio en este periodo.',
        withoutValue: (n) => `${n} ${n === 1 ? 'cita realizada no tiene' : 'citas realizadas no tienen'} precio registrado y no ${n === 1 ? 'suma' : 'suman'} al ingreso.`,
    },
    orders: {
        title: 'Pedidos',
        icon: PackageCheck,
        incomeLabel: 'Ingreso por pedidos entregados',
        incomeHint: 'Total de los pedidos en estado Entregado',
        countLabel: 'Pedidos entregados',
        countHint: 'Los que generan el ingreso',
        averageLabel: 'Valor promedio por pedido',
        chartTitle: 'Ingresos por pedidos entregados',
        basis: 'Cada pedido cuenta el día en que se entregó. Un pedido recibido, en curso o cancelado no suma nada.',
        empty: 'No se entregaron pedidos en este periodo.',
        withoutValue: () => null,
    },
};

/** Change against the previous period as a small arrow with its percentage, or the plain fact that there is nothing to compare with. */
function Change({ change, previous }) {
    if (change === null) return <span className="text-xs text-ink-muted">{previous === 0 ? 'Sin periodo anterior con ingresos' : ''}</span>;

    const Icon = change > 0 ? ArrowUpRight : change < 0 ? ArrowDownRight : Minus;
    const color = change > 0 ? 'text-accent-green' : change < 0 ? 'text-danger' : 'text-ink-muted';

    return (
        <span className={`inline-flex items-center gap-0.5 text-xs font-semibold ${color}`}>
            <Icon className="size-3.5" aria-hidden="true" />
            {percent(change)}
        </span>
    );
}

function Module({ kind, section, range, granularity, loading, onRetry }) {
    const copy = MODULES[kind];
    const Icon = copy.icon;

    if (section.error) {
        return (
            <Alert tone="warning">
                {copy.title}: {section.error}{' '}
                <button type="button" onClick={onRetry} className="font-semibold underline underline-offset-2">
                    Reintentar
                </button>
            </Alert>
        );
    }

    const { kpis, series, previousRange } = section.data;
    const money = (value) => formatMoney(value, kpis.currency);
    const withoutValue = kpis.withoutValue > 0 ? copy.withoutValue(kpis.withoutValue) : null;

    return (
        <section aria-labelledby={`economics-${kind}`} className="space-y-4">
            <div className="flex items-center gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <h2 id={`economics-${kind}`} className="text-base font-bold text-ink">{copy.title}</h2>
                    <p className="text-sm text-ink-muted">{copy.basis}</p>
                </div>
            </div>

            {kpis.mixedCurrencies && <Alert tone="warning">Los registros de este periodo están en más de una moneda, así que no se suman: sumar monedas distintas no tendría sentido.</Alert>}
            {withoutValue && <Alert tone="info">{withoutValue}</Alert>}

            <div className="grid gap-4 sm:grid-cols-2">
                <MetricCard label={copy.incomeLabel} hint={kpis.income ? (kpis.income.previous > 0 ? `Antes: ${money(kpis.income.previous)}` : copy.incomeHint) : copy.incomeHint} value={kpis.income?.value ?? null} formatValue={money} change={percent(kpis.income?.change)} icon={Coins} tone="green" />
                <MetricCard label={copy.countLabel} hint={kpis.count.previous > 0 ? `Antes: ${kpis.count.previous.toLocaleString('es')}` : copy.countHint} value={kpis.count.value} change={percent(kpis.count.change)} icon={ReceiptText} tone="blue" delay={60} />
                <MetricCard label={copy.averageLabel} hint={kpis.average?.previous != null ? `Antes: ${money(kpis.average.previous)}` : 'Ingreso entre registros'} value={kpis.average?.value ?? null} formatValue={money} icon={TrendingUp} tone="violet" delay={120} />
                <MetricCard label="Periodo anterior" hint={`${previousRange.from} – ${previousRange.to}`} value={kpis.income?.previous ?? null} formatValue={money} icon={Coins} tone="amber" delay={180} />
            </div>

            <ChartCard
                title={copy.chartTitle}
                description={`Por ${granularity}, frente al periodo anterior (${previousRange.from} – ${previousRange.to}) sobre la misma línea de tiempo. Pulsa una serie de la leyenda para ocultarla.`}
                loading={loading}
                height={280}
            >
                {!series || (series.total === 0 && (kpis.income?.previous ?? 0) === 0) ? (
                    <ChartEmpty type="line" title={series ? 'Sin ingresos en este periodo' : 'No se dibuja con varias monedas'} description={series ? copy.empty : 'Elige una sede o un periodo con una sola moneda.'} height={280} />
                ) : (
                    <SeriesChart
                        key={`${kind}-${range.from}-${granularity}`}
                        points={series.points}
                        series={[
                            { key: 'income', label: 'Periodo actual', className: 'text-accent-green' },
                            { key: 'previous', label: 'Periodo anterior', className: 'text-accent-amber' },
                        ]}
                        formatValue={money}
                        formatAxis={(value) => compact(value, kpis.currency)}
                        ariaLabel={`${copy.chartTitle} a lo largo del periodo, comparado con el periodo anterior`}
                    />
                )}
            </ChartCard>
        </section>
    );
}

/**
 * The economic view of Reportes: the value tied to services that were carried out (Reservas) and to orders that were delivered (Pedidos), with the comparison against the
 * previous period. The server decides what counts and which modules the person may read; none of it is «money received» because Ava records no payments, and the page says so.
 */
export default function EconomicsPanel({ economics, range, granularity, loading, onRetry }) {
    const kinds = ['bookings', 'orders'].filter((kind) => economics?.[kind]);

    return (
        <div className="space-y-8">
            <Card className="flex items-start gap-3 p-4 sm:p-5">
                <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-accent-amber/10 text-accent-amber">
                    <ReceiptText className="size-5" aria-hidden="true" />
                </span>
                <p className="text-sm text-ink-muted">
                    <strong className="text-ink">Ingreso asociado, no dinero cobrado.</strong> Ava no registra los pagos: estas cifras suman el valor de {kinds.map((kind) => (kind === 'bookings' ? 'los servicios realizados' : 'los pedidos entregados')).join(' y de ')}. Lo cancelado, pendiente o en curso no cuenta, y cada registro se cuenta una sola vez.
                </p>
            </Card>

            {kinds.map((kind) => (
                <Module key={kind} kind={kind} section={economics[kind]} range={range} granularity={granularity} loading={loading} onRetry={onRetry} />
            ))}
        </div>
    );
}
