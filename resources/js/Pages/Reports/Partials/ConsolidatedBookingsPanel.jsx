import { CalendarCheck, CalendarX, Layers } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import ChartCard from '@/Components/Charts/ChartCard';
import ChartEmpty from '@/Components/Charts/ChartEmpty';
import DonutChart from '@/Components/Charts/DonutChart';
import RankingList from '@/Components/Charts/RankingList';
import InputLabel from '@/Components/InputLabel';
import MetricCard from '@/Components/MetricCard';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';

const STATUS_OPTIONS = [{ value: '', label: 'Todos los estados' }, { value: 'booked', label: 'Reservada' }, { value: 'confirmed', label: 'Confirmada' }, { value: 'completed', label: 'Atendida' }, { value: 'no_show', label: 'No asistió' }, { value: 'cancelled', label: 'Cancelada' }];
const SOURCE_OPTIONS = [{ value: '', label: 'Todos los orígenes' }, { value: 'booker', label: 'Booker' }, { value: 'lava', label: 'Lava Studio' }, { value: 'ava', label: 'Agenda de Ava' }];
const STATUS_TONES = { booked: 'amber', confirmed: 'blue', completed: 'green', no_show: 'red', cancelled: 'neutral' };
const SOURCE_TONES = { booker: 'blue', lava: 'violet', ava: 'green' };

const total = (items) => items.reduce((sum, item) => sum + item.value, 0);

function Ranking({ title, items, loading }) {
    return (
        <ChartCard title={title} loading={loading} height={200}>
            {items.length === 0 ? <ChartEmpty type="ranking" title="Sin datos en este periodo" description="Cuando haya reservas con este dato lo verás aquí." height={200} /> : <RankingList items={items} />}
        </ChartCard>
    );
}

/**
 * Reservations of ANY system counted once: Booker and Lava Studio (reported to Ava) plus Ava's own agenda. It does not depend on the Workspace using Ava's agenda.
 * The total is the sum of the sources; filters (period and location are the page's own) travel to the server.
 */
export default function ConsolidatedBookingsPanel({ section, filters, onFilter, loading, onRetry }) {
    const [service, setService] = useState(filters.service ?? '');
    const [professional, setProfessional] = useState(filters.professional ?? '');

    if (section.error) {
        return (
            <Alert tone="warning">
                {section.error}{' '}
                <button type="button" onClick={onRetry} className="font-semibold underline underline-offset-2">Reintentar</button>
            </Alert>
        );
    }

    const data = section.data;
    const active = Object.values(filters).filter(Boolean).length;
    const submit = (event) => {
        event.preventDefault();
        onFilter({ service: service || undefined, professional: professional || undefined });
    };

    return (
        <section aria-label="Reservas de todos los orígenes" className="space-y-4">
            <div>
                <h2 className="text-base font-bold text-ink">Reservas de todos los orígenes</h2>
                <p className="text-sm text-ink-muted">Booker, Lava Studio y la agenda de Ava en un solo conteo; una reserva que aparece en dos sistemas se cuenta una vez.</p>
            </div>

            <form onSubmit={submit} role="search" aria-label="Filtros de reservas" className="grid gap-3 rounded-xl border border-line bg-card p-4 sm:grid-cols-2 lg:grid-cols-5">
                <div className="min-w-0">
                    <InputLabel htmlFor="rb_source" value="Origen" />
                    <Select id="rb_source" className="mt-1" value={filters.source ?? ''} onChange={(value) => onFilter({ source: value || undefined })} options={SOURCE_OPTIONS} />
                </div>
                <div className="min-w-0">
                    <InputLabel htmlFor="rb_status" value="Estado" />
                    <Select id="rb_status" className="mt-1" value={filters.status ?? ''} onChange={(value) => onFilter({ status: value || undefined })} options={STATUS_OPTIONS} />
                </div>
                <div className="min-w-0">
                    <InputLabel htmlFor="rb_service" value="Servicio" />
                    <TextInput id="rb_service" className="mt-1" value={service} onChange={(event) => setService(event.target.value)} placeholder="Nombre exacto" />
                </div>
                <div className="min-w-0">
                    <InputLabel htmlFor="rb_professional" value="Profesional" />
                    <TextInput id="rb_professional" className="mt-1" value={professional} onChange={(event) => setProfessional(event.target.value)} placeholder="Nombre exacto" />
                </div>
                <div className="flex items-end gap-2">
                    <SecondaryButton type="submit" className="min-h-10 justify-center">Aplicar</SecondaryButton>
                    {active > 0 && <SecondaryButton type="button" onClick={() => { setService(''); setProfessional(''); onFilter({ service: undefined, professional: undefined, status: undefined, source: undefined }); }} className="min-h-10 justify-center">Limpiar</SecondaryButton>}
                </div>
            </form>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="Total consolidado" hint="Sin contar dos veces la misma reserva" value={data.total} icon={Layers} tone="blue" />
                {data.bySource.map((item, index) => (
                    <MetricCard key={item.key} label={item.label} hint="Reservas de este origen" value={item.value} icon={item.key === 'ava' ? CalendarCheck : CalendarX} tone={SOURCE_TONES[item.key] ?? 'blue'} delay={(index + 1) * 60} />
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <ChartCard title="Por estado" description="Estado actual de las reservas del periodo." loading={loading} height={260}>
                    {total(data.byStatus) === 0 ? <ChartEmpty type="donut" title="Sin reservas en este periodo" description="Cuando haya reservas las verás aquí." height={260} /> : <DonutChart items={data.byStatus.map((item) => ({ ...item, tone: STATUS_TONES[item.key] ?? 'neutral' }))} totalLabel="Total" ariaLabel="Reservas por estado" />}
                </ChartCard>
                <ChartCard title="Por origen" description="De dónde vienen las reservas." loading={loading} height={260}>
                    {total(data.bySource) === 0 ? <ChartEmpty type="donut" title="Sin reservas en este periodo" description="Cuando haya reservas las verás aquí." height={260} /> : <DonutChart items={data.bySource.map((item) => ({ ...item, tone: SOURCE_TONES[item.key] ?? 'neutral' }))} totalLabel="Total" ariaLabel="Reservas por origen" />}
                </ChartCard>
                <Ranking title="Por sede" items={data.byLocation} loading={loading} />
                <Ranking title="Por servicio" items={data.byService} loading={loading} />
                <Ranking title="Por profesional" items={data.byProfessional} loading={loading} />
                <Ranking title="Por canal de origen" items={data.byOrigin} loading={loading} />
            </div>
        </section>
    );
}
