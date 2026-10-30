import axios from 'axios';
import { ChevronDown, RotateCw, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/Components/Badge';
import Pagination from '@/Components/Pagination';
import Select from '@/Components/Select';

const TONES = { succeeded: 'green', rejected: 'amber', failed: 'red', uncertain: 'amber', started: 'neutral' };
const RUN_LABELS = { pending: 'En cola', running: 'En curso', succeeded: 'Terminada', discarded: 'Descartada', failed: 'Falló' };
const RUN_TONES = { pending: 'neutral', running: 'blue', succeeded: 'green', discarded: 'neutral', failed: 'red' };
const FILTERS = [
    { value: '', label: 'Todas' },
    { value: 'succeeded', label: 'Hechas' },
    { value: 'rejected', label: 'Rechazadas' },
    { value: 'failed', label: 'Fallidas' },
    { value: 'uncertain', label: 'Sin certeza' },
    { value: 'started', label: 'Sin resultado' },
];

function Detail({ label, children }) {
    if (children === null || children === undefined || children === '') return null;

    return (
        <div className="flex justify-between gap-3">
            <dt className="text-ink-muted">{label}</dt>
            <dd className="min-w-0 text-right font-mono text-xs break-all text-ink">{children}</dd>
        </div>
    );
}

function Call({ call, simulated }) {
    return (
        <li className={`rounded-xl border bg-card p-3 ${simulated ? 'border-dashed border-warning/60' : 'border-line'}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="min-w-0 text-sm font-semibold text-ink">{call.step && <span className="mr-1.5 text-ink-muted">Paso {call.step} de {call.steps} ·</span>}{call.label}</p>
                <Badge tone={TONES[call.status] ?? 'neutral'}>{call.statusLabel}</Badge>
            </div>
            <p className="mt-1 text-xs text-ink-muted">{call.when}{call.durationMs !== null ? ` · ${call.durationMs} ms` : ''}{call.replayed ? ' · repetida' : ''}{call.retryOfPrevious ? ' · reintento' : ''}{simulated ? ' · simulada' : ''}</p>
            {call.reasonText ? (
                <dl className="mt-2 space-y-1 text-sm">
                    <div><dt className="inline font-semibold text-ink">Motivo: </dt><dd className="inline text-ink">{call.reasonText}</dd></div>
                    <div><dt className="inline font-semibold text-ink">Resultado: </dt><dd className="inline text-ink">{call.resultText}</dd></div>
                </dl>
            ) : (
                <p className="mt-2 text-sm text-ink">{call.summary}</p>
            )}
            <details className="group mt-2">
                <summary className="flex cursor-pointer list-none items-center gap-1 text-xs font-semibold text-primary">
                    <ChevronDown className="size-3.5 transition-transform group-open:rotate-180" aria-hidden="true" />
                    Detalles técnicos
                </summary>
                <dl className="mt-2 space-y-1 text-sm">
                    <Detail label="Herramienta">{call.tool}</Detail>
                    <Detail label="Tipo">{call.kind === 'write' ? 'Escritura' : 'Lectura'}</Detail>
                    <Detail label="Credencial">{call.auth === 'execution' ? 'Ejecución (vida corta)' : 'Token del asistente'}</Detail>
                    <Detail label="HTTP">{call.httpStatus}</Detail>
                    <Detail label="Motivo">{call.reason}</Detail>
                    <Detail label="Estado final">{call.resultState}</Detail>
                    <Detail label="Recurso">{call.resourceType ? `${call.resourceType} #${call.resourceId}` : null}</Detail>
                    <Detail label="Aviso al cliente">{call.notification !== 'none' ? call.notification : null}</Detail>
                    <Detail label="Ejecución">{call.executionRef}</Detail>
                    <Detail label="Mensaje de origen">{call.sourceMessageId ? `#${call.sourceMessageId}` : null}</Detail>
                    <Detail label="Estado de la ejecución">{call.run ? `${RUN_LABELS[call.run.status] ?? call.run.status} (${call.run.attempts} intento${call.run.attempts === 1 ? '' : 's'})` : null}</Detail>
                </dl>
            </details>
        </li>
    );
}

/**
 * «Actividad técnica» of ONE conversation, as a right side panel (a drawer: the chat stays visible on the left on wide screens and the panel takes the whole width on a phone).
 * It loads one page at a time from the server and only shows what the trace recorded: fixed codes, states and durations, never message text or credentials.
 */
export default function ActivityPanel({ conversationId, onClose }) {
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);

    const load = useCallback(() => {
        axios.get(route('conversations.activity', conversationId), { params: { page, ...(status ? { status } : {}) } })
            .then((response) => { setFailed(false); setData(response.data); })
            .catch(() => setFailed(true));
    }, [conversationId, page, status]);

    useEffect(load, [load]);
    useEffect(() => {
        const close = (event) => event.key === 'Escape' && onClose();
        document.addEventListener('keydown', close);

        return () => document.removeEventListener('keydown', close);
    }, [onClose]);

    // A portal: the conversations layout creates its own stacking context, which would leave the topbar above a fixed panel.
    return createPortal(
        <aside role="complementary" aria-label="Actividad técnica de la conversación" className="fixed inset-y-0 right-0 z-[60] flex w-full flex-col border-l border-line bg-card shadow-2xl sm:w-[26rem]">
            <header className="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-base font-bold text-ink">Actividad técnica</h2>
                    <p className="text-xs text-ink-muted">Qué herramientas usó el asistente en esta conversación. No incluye el texto de los mensajes.</p>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                    <button type="button" onClick={load} aria-label="Actualizar" className="grid size-9 place-items-center rounded-lg text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        <RotateCw className="size-4" aria-hidden="true" />
                    </button>
                    <button type="button" onClick={onClose} aria-label="Cerrar la actividad técnica" className="grid size-9 place-items-center rounded-lg text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        <X className="size-4" aria-hidden="true" />
                    </button>
                </div>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                {failed && <p role="alert" className="text-sm text-danger">No se pudo cargar la actividad.</p>}
                {!failed && data === null && <p className="text-sm text-ink-muted">Cargando…</p>}
                {data?.simulated && (
                    <p role="note" className="rounded-xl border border-warning/50 bg-warning/10 p-3 text-sm font-semibold text-ink">
                        DEMOSTRACIÓN — Actividad técnica simulada. Ninguno de estos pasos es una llamada real: sirve para mostrar cómo se lee el recorrido del asistente. No se envió nada ni se usó ninguna API.
                    </p>
                )}
                {data && !data.ready && <p className="text-sm text-ink-muted">El registro de actividad todavía no está disponible en este entorno.</p>}

                {data?.ready && (
                    <>
                        {data.runs.length > 0 && page === 1 && (
                            <section aria-label="Ejecuciones del flujo">
                                <h3 className="mb-2 text-xs font-semibold tracking-wider text-ink-muted uppercase">Ejecuciones del flujo</h3>
                                <ul className="space-y-2">
                                    {data.runs.map((run) => (
                                        <li key={run.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line px-3 py-2 text-sm">
                                            <span className="text-ink">
                                                <span className="font-mono text-xs text-ink-muted">{run.ref}</span> · {run.when}
                                                {run.durationMs !== null && <span className="text-ink-muted"> · {run.durationMs} ms</span>}
                                                {run.attempts > 1 && <span className="text-ink-muted"> · {run.attempts} intentos</span>}
                                            </span>
                                            <Badge tone={RUN_TONES[run.status] ?? 'neutral'}>{RUN_LABELS[run.status] ?? run.status}</Badge>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section aria-label="Herramientas usadas">
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <h3 className="text-xs font-semibold tracking-wider text-ink-muted uppercase">Herramientas</h3>
                                <Select size="sm" aria-label="Filtrar por resultado" className="w-40" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={FILTERS} />
                            </div>
                            {data.calls.length === 0 ? (
                                <p className="text-sm text-ink-muted">{status ? 'Ninguna llamada coincide con el filtro.' : 'El asistente no ha usado herramientas en esta conversación.'}</p>
                            ) : (
                                <ul className="space-y-2">{data.calls.map((call) => <Call key={call.id} call={call} simulated={data.simulated} />)}</ul>
                            )}
                        </section>
                    </>
                )}
            </div>

            {data?.ready && data.meta.total > 0 && <Pagination meta={data.meta} onPage={setPage} noun="llamadas" />}
        </aside>,
        document.body,
    );
}
