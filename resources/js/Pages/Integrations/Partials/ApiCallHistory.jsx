import axios from 'axios';
import { useEffect, useState } from 'react';
import Badge from '@/Components/Badge';
import DateField from '@/Components/DateField';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

const OUTCOME = {
    success: { tone: 'green', label: 'Correcto' },
    http_error: { tone: 'red', label: 'Error HTTP' },
    timeout: { tone: 'amber', label: 'Tiempo agotado' },
    connection_error: { tone: 'red', label: 'Sin conexión' },
};
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

/** Historial de llamados: the redacted record of the outbound API calls of this Workspace. Secrets never get here: they were redacted before being stored. */
export default function ApiCallHistory() {
    const [filters, setFilters] = useState({ integration: '', outcome: '', status: '', operation: '', from: '', to: '', page: 1 });
    const [data, setData] = useState(null);
    const [detail, setDetail] = useState(null);

    useEffect(() => {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
        axios.get(route('integrations.calls.index'), { params }).then((response) => setData(response.data)).catch(() => setData({ data: [], last_page: 1 }));
    }, [filters]);

    const change = (name) => (event) => setFilters((previous) => ({ ...previous, [name]: event.target.value, page: 1 }));
    const open = (id) => axios.get(route('integrations.calls.show', id)).then((response) => setDetail(response.data));

    return (
        <section aria-labelledby="calls-heading" className="space-y-4">
            <div>
                <h2 id="calls-heading" className="text-lg font-bold text-ink">Historial de llamados</h2>
                <p className="text-sm text-ink-muted">Llamados reales a APIs externas. Las llaves, tokens, cookies y datos de tarjetas se ocultan antes de guardarse; el contenido de las conversaciones no se registra.</p>
            </div>

            <div className="flex flex-wrap gap-2">
                <TextInput aria-label="Integración" placeholder="Integración (wompi, n8n…)" value={filters.integration} onChange={change('integration')} className="max-w-[12rem]" />
                <TextInput aria-label="Operación" placeholder="Operación" value={filters.operation} onChange={change('operation')} className="max-w-[12rem]" />
                <select aria-label="Resultado" value={filters.outcome} onChange={change('outcome')} className="rounded-md border border-field bg-card px-2 py-2 text-sm">
                    <option value="">Todos los resultados</option>
                    {Object.entries(OUTCOME).map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}
                </select>
                <TextInput aria-label="Código HTTP" placeholder="Código HTTP" inputMode="numeric" maxLength={3} value={filters.status} onChange={change('status')} className="max-w-[8rem]" />
                <DateField id="calls_from" label="Desde" value={filters.from} onChange={(value) => setFilters((p) => ({ ...p, from: value ?? '', page: 1 }))} className="w-44" />
                <DateField id="calls_to" label="Hasta" value={filters.to} onChange={(value) => setFilters((p) => ({ ...p, to: value ?? '', page: 1 }))} className="w-44" />
            </div>

            {data === null ? (
                <p className="text-sm text-ink-muted">Cargando…</p>
            ) : data.data.length === 0 ? (
                <p className="rounded-xl border border-line py-8 text-center text-sm text-ink-muted">Todavía no hay llamados registrados.</p>
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
                    <SecondaryButton type="button" disabled={filters.page <= 1} onClick={() => setFilters((p) => ({ ...p, page: p.page - 1 }))}>Anterior</SecondaryButton>
                    <span>Página {data.current_page} de {data.last_page}</span>
                    <SecondaryButton type="button" disabled={filters.page >= data.last_page} onClick={() => setFilters((p) => ({ ...p, page: p.page + 1 }))}>Siguiente</SecondaryButton>
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
