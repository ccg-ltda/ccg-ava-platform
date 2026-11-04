import axios from 'axios';
import { ChevronDown, RotateCw, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/Components/Badge';
import JsonBlock from '@/Components/JsonBlock';
import Pagination from '@/Components/Pagination';
import Select from '@/Components/Select';

const TONES = { succeeded: 'green', rejected: 'amber', failed: 'red', uncertain: 'amber', started: 'neutral' };
const RUN_LABELS = { pending: 'En cola', running: 'En curso', succeeded: 'Terminada', discarded: 'Descartada', failed: 'Falló' };
const RUN_TONES = { pending: 'neutral', running: 'blue', succeeded: 'green', discarded: 'neutral', failed: 'red' };
const FILTERS = [
    { value: '', label: 'Todos los estados' },
    { value: 'succeeded', label: 'Exitosos' },
    { value: 'rejected', label: 'Rechazados' },
    { value: 'failed', label: 'Fallidos' },
    { value: 'uncertain', label: 'Sin certeza' },
    { value: 'started', label: 'Pendientes' },
];
const NOT_REPORTED = 'No reportado';

function Fact({ label, children }) {
    if (children === null || children === undefined || children === '') return null;

    return (
        <div className="flex justify-between gap-3">
            <dt className="shrink-0 text-ink-muted">{label}</dt>
            <dd className="min-w-0 text-right font-mono text-xs break-all text-ink">{children}</dd>
        </div>
    );
}

/** Request and answer of ONE step, loaded when the step is opened. Anything nobody reported says so; secrets were removed before they were stored. */
function StepDetail({ conversationId, call, simulated }) {
    const [detail, setDetail] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (simulated) return;
        axios.get(route('conversations.activity.call', [conversationId, call.id])).then((response) => setDetail(response.data)).catch(() => setFailed(true));
    }, [conversationId, call.id, simulated]);

    if (simulated) return <p className="mt-3 text-xs text-ink-muted">Paso de demostración: no tiene solicitud ni respuesta reales.</p>;
    if (failed) return <p role="alert" className="mt-3 text-xs text-danger">No se pudo cargar el detalle.</p>;
    if (!detail) return <p className="mt-3 text-xs text-ink-muted">Cargando detalle…</p>;

    const { request, response } = detail;

    return (
        <div className="mt-3 space-y-4 border-t border-line pt-3">
            {detail.truncated && <p className="text-xs text-accent-amber">Algún contenido era demasiado grande y se guardó recortado.</p>}

            <section aria-label="Solicitud" className="space-y-2">
                <h3 className="text-xs font-bold tracking-wider text-ink uppercase">Solicitud</h3>
                <dl className="space-y-1 text-sm">
                    <Fact label="Herramienta">{detail.tool}</Fact>
                    <Fact label="Método">{request.method ?? NOT_REPORTED}</Fact>
                    <Fact label="Endpoint">{request.endpoint ?? NOT_REPORTED}</Fact>
                    <Fact label="Correlación">{detail.correlationId ?? detail.executionId ?? NOT_REPORTED}</Fact>
                    <Fact label="Fecha y hora">{request.at ?? NOT_REPORTED}</Fact>
                </dl>
                <div className="rounded-lg border border-line bg-canvas px-3 py-2">
                    <h4 className="text-xs font-semibold text-ink-muted">Mensaje que activó la API</h4>
                    <p className="mt-0.5 text-sm break-words text-ink">{request.message ? (request.message.text ?? `(mensaje de tipo ${request.message.type})`) : NOT_REPORTED}</p>
                </div>
                <JsonBlock label="Headers" value={request.headers} />
                <JsonBlock label="Parámetros" value={request.query} emptyText="Sin parámetros" />
                <JsonBlock label="Body enviado" value={request.body} emptyText="Sin body enviado o no reportado" />
            </section>

            <section aria-label="Respuesta" className="space-y-2">
                <h3 className="text-xs font-bold tracking-wider text-ink uppercase">Respuesta</h3>
                <dl className="space-y-1 text-sm">
                    <Fact label="Código HTTP">{response.httpStatus ?? NOT_REPORTED}</Fact>
                    <Fact label="Estado">{detail.statusLabel}</Fact>
                    <Fact label="Duración">{response.durationMs !== null ? `${response.durationMs} ms` : NOT_REPORTED}</Fact>
                    <Fact label="Finalizó">{response.at ?? (detail.status === 'started' ? 'Sin finalizar' : NOT_REPORTED)}</Fact>
                </dl>
                {(response.errorMessage || response.errorClass) && (
                    <p role="note" className="rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-ink">
                        {response.errorClass && <span className="font-mono text-xs">{response.errorClass}: </span>}
                        {response.errorMessage}
                    </p>
                )}
                <JsonBlock label="Headers de la respuesta" value={response.headers} />
                <JsonBlock label="Body de la respuesta" value={response.body} emptyText="Sin body de respuesta o no reportado" />
            </section>
        </div>
    );
}

function Step({ conversationId, call, simulated }) {
    const [open, setOpen] = useState(false);
    const endpoint = call.method || call.endpoint ? `${call.method ?? ''} ${call.endpoint ?? ''}`.trim() : null;

    return (
        <li className={`rounded-xl border bg-card ${simulated ? 'border-dashed border-warning/60' : 'border-line'}`}>
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="flex w-full items-start gap-3 rounded-xl p-3 text-left hover:bg-canvas/60 focus-visible:outline-2 focus-visible:outline-primary"
            >
                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-primary-soft text-xs font-bold text-primary" aria-label={`Paso ${call.step ?? '—'}`}>{call.step ?? '—'}</span>
                <span className="min-w-0 flex-1 space-y-1">
                    <span className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                        <span className="min-w-0 text-sm font-semibold break-words text-ink">{call.label}</span>
                        <Badge tone={TONES[call.status] ?? 'neutral'}>{call.statusLabel}</Badge>
                    </span>
                    {endpoint && <span className="block font-mono text-xs break-all text-ink-muted">{endpoint}</span>}
                    {call.trigger?.text && <span className="block truncate text-xs text-ink-muted">Mensaje: «{call.trigger.text}»</span>}
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-muted">
                        {call.httpStatus !== null && call.httpStatus !== undefined && <span className="font-mono font-semibold text-ink">HTTP {call.httpStatus}</span>}
                        {call.durationMs !== null && call.durationMs !== undefined && <span>{call.durationMs} ms</span>}
                        <span>{call.when}</span>
                        <span>· {call.engineLabel ?? 'No informado'}</span>
                        {call.replayed && <span>· repetida</span>}
                        {call.retryOfPrevious && <span>· reintento</span>}
                        {simulated && <span>· simulada</span>}
                    </span>
                </span>
                <ChevronDown className={`mt-1 size-4 shrink-0 text-ink-muted transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
            </button>

            {open && (
                <div className="px-3 pb-3">
                    <p className="text-sm text-ink">{call.reasonText ? `${call.reasonText} ${call.resultText}` : call.summary}</p>
                    <dl className="mt-2 space-y-1 text-sm">
                        <Fact label="Motor">{call.engineLabel}</Fact>
                        <Fact label="Ejecución">{call.executionId ?? call.executionRef}</Fact>
                        <Fact label="Recurso">{call.resourceType ? `${call.resourceType} #${call.resourceId}` : null}</Fact>
                        <Fact label="Aviso al cliente">{call.notification !== 'none' ? call.notification : null}</Fact>
                        <Fact label="Estado de la ejecución">{call.run ? `${RUN_LABELS[call.run.status] ?? call.run.status} (${call.run.attempts} intento${call.run.attempts === 1 ? '' : 's'})` : null}</Fact>
                    </dl>
                    <StepDetail conversationId={conversationId} call={call} simulated={simulated} />
                </div>
            )}
        </li>
    );
}

/**
 * «Actividad técnica» of ONE conversation, as a right side panel (a drawer: the chat stays visible on the left on wide screens and the panel takes the whole width on a phone).
 * It lists the API calls of the conversation as numbered, chronological steps; opening one shows its request and answer (redacted before they were stored). It loads one
 * page at a time and shows only what was recorded or reported: a missing field says «No reportado».
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
        <aside role="complementary" aria-label="Actividad técnica de la conversación" className="fixed inset-y-0 right-0 z-[60] flex w-full flex-col border-l border-line bg-card shadow-2xl sm:w-[30rem]">
            <header className="flex items-start justify-between gap-3 border-b border-line px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-base font-bold text-ink">Actividad técnica</h2>
                    <p className="text-xs text-ink-muted">Las APIs que usó el asistente en esta conversación, paso a paso. Los tokens y las credenciales se ocultan antes de guardarse.</p>
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

                        <section aria-label="Pasos">
                            <div className="mb-2 flex items-center justify-between gap-3">
                                <h3 className="text-xs font-semibold tracking-wider text-ink-muted uppercase">Pasos</h3>
                                <Select size="sm" aria-label="Filtrar por resultado" className="w-44" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={FILTERS} />
                            </div>
                            {data.calls.length === 0 ? (
                                <p className="text-sm text-ink-muted">{status ? 'Ningún paso coincide con el filtro.' : 'No hay actividad registrada ni reportada para esta conversación. Si el flujo corre en n8n, el flujo debe reportar sus llamadas a Ava.'}</p>
                            ) : (
                                <ul className="space-y-2">{data.calls.map((call) => <Step key={call.id} conversationId={conversationId} call={call} simulated={data.simulated} />)}</ul>
                            )}
                        </section>
                    </>
                )}
            </div>

            {data?.ready && data.meta.total > 0 && <Pagination meta={data.meta} onPage={setPage} noun="pasos" />}
        </aside>,
        document.body,
    );
}
