import axios from 'axios';
import { useEffect, useState } from 'react';
import Badge from '@/Components/Badge';
import DateField from '@/Components/DateField';
import InputLabel from '@/Components/InputLabel';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import { activeFilterCount, callQuery, cleanStatus, EMPTY_FILTERS } from '@/lib/apiCallFilters';

const OUTCOME = {
    success: { tone: 'green', label: 'Correcto' },
    http_error: { tone: 'red', label: 'Error HTTP' },
    timeout: { tone: 'amber', label: 'Tiempo agotado' },
    connection_error: { tone: 'red', label: 'Sin conexión' },
};
const OUTCOME_OPTIONS = [{ value: '', label: 'Todos los resultados' }, ...Object.entries(OUTCOME).map(([value, { label }]) => ({ value, label }))];
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

/** Historial de llamados: the redacted record of the outbound API calls of this Workspace. Secrets never get here: they were redacted before being stored. */
export default function ApiCallHistory() {
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [detail, setDetail] = useState(null);

    useEffect(() => {
        axios.get(route('integrations.calls.index'), { params: callQuery(filters, page) }).then((response) => setData(response.data)).catch(() => setData({ data: [], last_page: 1 }));
    }, [filters, page]);

    // Any change of a filter goes back to the first page; the list answers as the filters change (there is no «Filtrar» button to press).
    const set = (name, value) => {
        setFilters((previous) => ({ ...previous, [name]: value }));
        setPage(1);
    };
    const change = (name) => (event) => set(name, name === 'status' ? cleanStatus(event.target.value) : event.target.value);
    const active = activeFilterCount(filters);
    const clear = () => {
        setFilters(EMPTY_FILTERS);
        setPage(1);
    };
    const open = (id) => axios.get(route('integrations.calls.show', id)).then((response) => setDetail(response.data));

    return (
        <section aria-labelledby="calls-heading" className="space-y-4">
            <div>
                <h2 id="calls-heading" className="text-lg font-bold text-ink">Historial de llamados</h2>
                <p className="text-sm text-ink-muted">Llamados reales a APIs externas. Las llaves, tokens, cookies y datos de tarjetas se ocultan antes de guardarse; el contenido de las conversaciones no se registra.</p>
            </div>

            <div role="search" aria-label="Filtros del historial de llamados" className="rounded-xl border border-line bg-card p-4">
                <div className="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    <div className="min-w-0">
                        <InputLabel htmlFor="calls_integration" value="Integración" />
                        <TextInput id="calls_integration" placeholder="wompi, n8n, booker…" value={filters.integration} onChange={change('integration')} className="mt-1" />
                    </div>
                    <div className="min-w-0">
                        <InputLabel htmlFor="calls_operation" value="Operación" />
                        <TextInput id="calls_operation" placeholder="get_transaction…" value={filters.operation} onChange={change('operation')} className="mt-1" />
                    </div>
                    <div className="min-w-0">
                        <InputLabel htmlFor="calls_outcome" value="Resultado" />
                        <Select id="calls_outcome" value={filters.outcome} onChange={(value) => set('outcome', value)} options={OUTCOME_OPTIONS} className="mt-1" />
                    </div>
                    <div className="min-w-0">
                        <InputLabel htmlFor="calls_status" value="Código HTTP" />
                        <TextInput id="calls_status" placeholder="200, 404, 503…" inputMode="numeric" maxLength={3} value={filters.status} onChange={change('status')} className="mt-1" />
                    </div>
                    <DateField id="calls_from" label="Desde" value={filters.from} max={filters.to || undefined} onChange={(value) => set('from', value ?? '')} />
                    <DateField id="calls_to" label="Hasta" value={filters.to} min={filters.from || undefined} onChange={(value) => set('to', value ?? '')} />
                </div>
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
                    <p className="text-sm text-ink-muted" aria-live="polite">
                        {data && data.total != null ? `${data.total} ${data.total === 1 ? 'llamado' : 'llamados'}` : 'Buscando…'}
                        {active > 0 ? ` · ${active} ${active === 1 ? 'filtro activo' : 'filtros activos'}` : ''}
                    </p>
                    <SecondaryButton type="button" onClick={clear} disabled={active === 0} className="min-h-10 justify-center px-5">Limpiar filtros</SecondaryButton>
                </div>
            </div>

            {data === null ? (
                <p className="text-sm text-ink-muted">Cargando…</p>
            ) : data.data.length === 0 ? (
                <p className="rounded-xl border border-line py-8 text-center text-sm text-ink-muted">{active > 0 ? 'Ningún llamado coincide con los filtros.' : 'Todavía no hay llamados registrados.'}</p>
            ) : (
                <div className="overflow-x-auto rounded-xl border border-line">
                    <table className="w-full text-left text-sm">
                        <thead className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">
                            <tr><th className="p-3">Fecha</th><th className="pr-3">Integración</th><th className="pr-3">Operación</th><th className="pr-3">Método y ruta</th><th className="pr-3">HTTP</th><th className="pr-3">Resultado</th><th className="pr-3">Duración</th><th className="pr-3">Correlación</th><th /></tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {data.data.map((row) => (
                                <tr key={row.id}>
                                    <td className="p-3 whitespace-nowrap">{when(row.at)}</td>
                                    <td className="pr-3">{row.integration}</td>
                                    <td className="pr-3">{row.operation}</td>
                                    <td className="pr-3 font-mono text-xs break-all">{row.method} {row.host}{row.path}</td>
                                    <td className="pr-3">{row.status ?? '—'}</td>
                                    <td className="pr-3"><Badge tone={OUTCOME[row.outcome]?.tone ?? 'neutral'}>{OUTCOME[row.outcome]?.label ?? row.outcome}</Badge></td>
                                    <td className="pr-3 whitespace-nowrap">{row.durationMs} ms</td>
                                    <td className="pr-3 font-mono text-xs">{row.correlationId.slice(0, 8)}</td>
                                    <td className="pr-3"><button type="button" onClick={() => open(row.id)} className="text-xs font-semibold text-primary focus-visible:outline-2">Ver detalle</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <SecondaryButton type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>Anterior</SecondaryButton>
                    <span>Página {data.current_page} de {data.last_page}</span>
                    <SecondaryButton type="button" disabled={page >= data.last_page} onClick={() => setPage((current) => current + 1)}>Siguiente</SecondaryButton>
                </div>
            )}

            {detail && (
                <div role="dialog" aria-label="Detalle del llamado" className="space-y-3 rounded-xl border border-line bg-canvas/40 p-4 text-sm">
                    <div className="flex items-start justify-between">
                        <strong>{detail.method} {detail.host}{detail.path}</strong>
                        <button type="button" onClick={() => setDetail(null)} className="text-xs font-semibold text-primary">Cerrar</button>
                    </div>
                    <p className="text-xs text-ink-muted">Correlación {detail.correlationId} · {detail.durationMs} ms · {detail.context.workspace ?? 'plataforma'}{detail.truncated ? ' · contenido truncado' : ''}</p>
                    <div className="grid gap-3 md:grid-cols-2">
                        <div>
                            <h3 className="font-semibold">Solicitud</h3>
                            <pre className="mt-1 max-h-60 overflow-auto rounded bg-card p-2 text-xs whitespace-pre-wrap">{JSON.stringify(detail.request.headers, null, 1)}</pre>
                            <pre className="mt-1 max-h-60 overflow-auto rounded bg-card p-2 text-xs whitespace-pre-wrap">{detail.request.body ?? 'Sin cuerpo registrado.'}</pre>
                        </div>
                        <div>
                            <h3 className="font-semibold">Respuesta {detail.status ?? ''}</h3>
                            <pre className="mt-1 max-h-60 overflow-auto rounded bg-card p-2 text-xs whitespace-pre-wrap">{JSON.stringify(detail.response.headers, null, 1)}</pre>
                            <pre className="mt-1 max-h-60 overflow-auto rounded bg-card p-2 text-xs whitespace-pre-wrap">{detail.response.body ?? 'Sin cuerpo registrado.'}</pre>
                        </div>
                    </div>
                    {detail.error && <p className="text-danger">Error: {detail.error.type} — {detail.error.message}{detail.error.timeout ? ' (tiempo agotado)' : ''}</p>}
                </div>
            )}
        </section>
    );
}
