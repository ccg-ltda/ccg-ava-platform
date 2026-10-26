import { CalendarCheck, CalendarClock, CalendarX, Contact, MailCheck, PackageCheck, ShoppingCart, UserPlus, UserX, Wallet } from 'lucide-react';
import Alert from '@/Components/Alert';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import DonutChart from '@/Components/Charts/DonutChart';
import RankingList from '@/Components/Charts/RankingList';
import MetricCard from '@/Components/MetricCard';
import { formatMoney } from '@/lib/format';

const appointmentTones = { requested: 'amber', confirmed: 'blue', completed: 'green', no_show: 'red', cancelled: 'neutral' };
const orderTones = { received: 'amber', confirmed: 'blue', preparing: 'violet', ready: 'violet', dispatched: 'blue', delivered: 'green', cancelled: 'neutral' };
const consentTones = { granted: 'green', unknown: 'neutral', revoked: 'red' };

const sum = (items) => items.reduce((total, item) => total + item.value, 0);

/** A donut with its empty state: a total of zero is said, never drawn. */
function Distribution({ title, description, items, tones, ariaLabel, loading }) {
    return (
        <ChartCard title={title} description={description} loading={loading} height={260}>
            {sum(items) === 0 ? (
                <ChartEmpty type="donut" title="Sin datos en este periodo" description="Cuando haya registros los verás aquí." height={260} />
            ) : (
                <DonutChart items={items.map((item) => ({ ...item, tone: tones?.[item.key] ?? 'neutral' }))} totalLabel="Total" ariaLabel={ariaLabel} />
            )}
        </ChartCard>
    );
}

function Ranking({ title, description, items, loading, share = false, formatValue }) {
    return (
        <ChartCard title={title} description={description} loading={loading} height={200}>
            {items.length === 0 ? <ChartEmpty type="ranking" title="Sin datos en este periodo" description="Cuando haya registros los verás aquí." height={200} /> : <RankingList items={items} share={share} formatValue={formatValue} />}
        </ChartCard>
    );
}

function Cards({ children }) {
    return <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{children}</div>;
}

function Bookings({ data, loading }) {
    const k = data.kpis;
    const money = (value) => formatMoney(value, k.currency);

    return (
        <>
            <Cards>
                <MetricCard label="Citas del periodo" hint="Por fecha de la cita, sin contar las futuras" value={k.total} icon={CalendarCheck} tone="blue" />
                <MetricCard label="Realizadas" hint="Atendidas" value={k.completed} icon={CalendarCheck} tone="green" delay={60} />
                <MetricCard label="Canceladas" hint="Se cancelaron antes de la cita" value={k.cancelled} icon={CalendarX} tone="amber" delay={120} />
                <MetricCard label="No asistieron" hint="Marcadas como no asistió" value={k.noShow} icon={UserX} tone="violet" delay={180} />
                <MetricCard label="Próximas" hint="Pendientes o confirmadas, de mañana en adelante" value={k.upcoming} icon={CalendarClock} tone="blue" delay={240} />
                <MetricCard
                    label="Valor registrado"
                    hint={k.withoutPrice > 0 ? `Citas realizadas con precio. ${k.withoutPrice} sin precio no suman.` : 'Precio guardado en las citas realizadas'}
                    value={k.recordedValue}
                    formatValue={money}
                    icon={Wallet}
                    tone="green"
                    delay={300}
                />
            </Cards>
            <div className="grid gap-6 lg:grid-cols-2">
                <Distribution title="Estado de las citas" description="Citas del periodo por su estado actual." items={data.byStatus} tones={appointmentTones} ariaLabel="Distribución de las citas por estado" loading={loading} />
                <Ranking title="Servicios más pedidos" description="Citas no canceladas por servicio." items={data.byService} loading={loading} />
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                <Ranking title="Por profesional" description="Citas no canceladas por profesional." items={data.byProfessional} loading={loading} />
                <Ranking title="Cómo llegaron" description="Quién creó la cita: el negocio, la página de reservas o el asistente." items={data.byOrigin} share loading={loading} />
            </div>
        </>
    );
}

function Orders({ data, loading }) {
    const k = data.kpis;
    const money = (value) => formatMoney(value, k.currency);

    return (
        <>
            {k.mixedCurrencies && <Alert tone="warning">Los pedidos de este periodo se tomaron en más de una moneda, así que no se suman las ventas: sumar monedas distintas no tendría sentido.</Alert>}
            <Cards>
                <MetricCard label="Pedidos" hint="Recibidos en el periodo" value={k.total} icon={ShoppingCart} tone="blue" />
                <MetricCard label="Entregados" hint="Llegaron a su destino" value={k.delivered} icon={PackageCheck} tone="green" delay={60} />
                <MetricCard label="En curso" hint="Aún no entregados ni cancelados" value={k.open} icon={ShoppingCart} tone="amber" delay={120} />
                <MetricCard label="Cancelados" hint="No cuentan como ventas" value={k.cancelled} icon={CalendarX} tone="violet" delay={180} />
                <MetricCard label="Ventas" hint="Suma de los pedidos no cancelados" value={k.sales} formatValue={money} icon={Wallet} tone="green" delay={240} />
                <MetricCard label="Ticket promedio" hint="Ventas entre pedidos no cancelados" value={k.averageTicket} formatValue={money} icon={Wallet} tone="blue" delay={300} />
            </Cards>
            <div className="grid gap-6 lg:grid-cols-2">
                <Distribution title="Estado de los pedidos" description="Pedidos del periodo por su estado actual." items={data.byStatus} tones={orderTones} ariaLabel="Distribución de los pedidos por estado" loading={loading} />
                <Ranking title="Productos más vendidos" description="Unidades en pedidos no cancelados." items={data.topProducts} formatValue={(value) => `${value.toLocaleString('es')} u.`} loading={loading} />
            </div>
            <Ranking title="Recogida o entrega" description="Cómo se entregan los pedidos del periodo." items={data.byMethod} share loading={loading} />
        </>
    );
}

function Customers({ data, loading }) {
    const k = data.kpis;

    return (
        <>
            <Cards>
                <MetricCard label="Clientes" hint="Fichas del Workspace" value={k.total} icon={Contact} tone="blue" />
                <MetricCard label="Activos" hint="No desactivados" value={k.active} icon={Contact} tone="green" delay={60} />
                <MetricCard label="Nuevos" hint="Fichas creadas en el periodo" value={k.new} icon={UserPlus} tone="violet" delay={120} />
                <MetricCard label="Con consentimiento" hint="Activos que autorizaron recibir campañas" value={k.reachable} icon={MailCheck} tone="amber" delay={180} />
            </Cards>
            <div className="grid gap-6 lg:grid-cols-2">
                <Distribution title="Consentimiento" description="Si autorizaron o no recibir mensajes de campañas." items={data.byConsent} tones={consentTones} ariaLabel="Distribución de los clientes por consentimiento" loading={loading} />
                <Ranking title="Cómo llegaron" description="Origen de la ficha de cliente." items={data.bySource} share loading={loading} />
            </div>
        </>
    );
}

/**
 * The reports of Agenda, Pedidos and Clientes (the stock lives in Catálogo, not here). The server computes every figure from those modules' own tables for the active Workspace; this only draws it.
 * A section that could not be read says so (and offers to retry) instead of showing zeros.
 */
export default function OperationsPanel({ kind, section, loading, onRetry }) {
    if (!section) return null;

    if (section.error) {
        return (
            <Alert tone="warning">
                {section.error}{' '}
                <button type="button" onClick={onRetry} className="font-semibold underline underline-offset-2">
                    Reintentar
                </button>
            </Alert>
        );
    }

    const Body = { bookings: Bookings, orders: Orders, customers: Customers }[kind];

    return <Body data={section.data} loading={loading} />;
}
