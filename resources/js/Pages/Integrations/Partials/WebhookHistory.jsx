import axios from 'axios';
import { router } from '@inertiajs/react';
import { RotateCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import Badge from '@/Components/Badge';
import DateField from '@/Components/DateField';
import InputLabel from '@/Components/InputLabel';
import Pagination from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';

const STATUS = {
    pending: { tone: 'neutral', label: 'En cola' },
    sending: { tone: 'blue', label: 'Enviando' },
    delivered: { tone: 'green', label: 'Entregado' },
    failed: { tone: 'amber', label: 'Fallido, se reintentará' },
    abandoned: { tone: 'red', label: 'Abandonado' },
};

const ERRORS = {
    timeout: 'Tiempo agotado',
    tls: 'Error de certificado o TLS',
    dns: 'No se encontró el servidor',
    connection: 'No se pudo conectar',
    unsafe_target: 'Destino no permitido',
    redirect_not_followed: 'Respondió con una redirección (no se sigue)',
    response_too_large: 'Respuesta demasiado grande',
    endpoint_disabled: 'Webhook desactivado',
    attempt_lost: 'Intento interrumpido',
};

const reason = (delivery) => {
    if (!delivery.error) return delivery.responseStatus ? `Respondió ${delivery.responseStatus}` : '—';
    if (delivery.error.startsWith('http_')) return `El destino respondió ${delivery.responseStatus}`;

    return ERRORS[delivery.error] ?? delivery.error;
};

const STATUS_OPTIONS = [{ value: '', label: 'Todos los estados' }, ...Object.entries(STATUS).map(([value, { label }]) => ({ value, label }))];

export const EMPTY_FILTERS = { webhook: '', event: '', status: '', from: '', to: '' };

/**
 * Subscriptions and deliveries (section B of the Webhooks tab): the delivery history of the whole Workspace, ONE PAGE at a time (the server paginates and filters; the
 * browser never holds the full history). A failed or abandoned delivery can be retried; it is re-sent with the same event id, so a consumer that deduplicates by it
 * processes it once. Never the body that was sent and never a secret.
 */
export default function WebhookHistory({ webhooks, filters, onFilters, canRetry }) {
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);

    const load = useCallback(() => {
        const params = { page, ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')) };

        axios.get(route('integrations.webhooks.history'), { params }).then((response) => { setFailed(false); setData(response.data); }).catch(() => setFailed(true));
    }, [page, filters]);

    useEffect(load, [load]);
    useEffect(() => setPage(1), [filters]);

    const change = (patch) => onFilters({ ...filters, ...patch });
    const retry = (delivery) => router.post(route('integrations.webhooks.retry', [delivery.webhookId, delivery.id]), {}, { preserveScroll: true, onFinish: () => setTimeout(load, 600) });
    const rows = data?.deliveries;
    const endpoints = [{ value: '', label: 'Todos los webhooks' }, ...webhooks.endpoints.map((endpoint) => ({ value: String(endpoint.id), label: endpoint.name }))];
    const events = [{ value: '', label: 'Todos los eventos' }, ...webhooks.events.map((event) => ({ value: event.key, label: event.key })), { value: 'webhook.test', label: 'webhook.test' }];
    const filtered = Object.values(filters).some((value) => value !== '');

    return (
        <div className="space-y-4" id="webhook-history">
            <div>
                <h3 className="text-base font-bold text-ink">Suscripciones y entregas</h3>
                <p className="text-sm text-ink-muted">Cada webhook recibe solo los eventos que suscribiste (se editan en «Editar»). Aquí ves cada envío: la lista se pagina en el servidor y el contenido enviado no se guarda.</p>
            </div>

            <div role="search" aria-label="Filtros de entregas" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <InputLabel htmlFor="wh_f_webhook" value="Webhook" />
                    <Select id="wh_f_webhook" value={filters.webhook} onChange={(webhook) => change({ webhook })} options={endpoints} />
                </div>
                <div>
                    <InputLabel htmlFor="wh_f_event" value="Evento" />
                    <Select id="wh_f_event" value={filters.event} onChange={(event) => change({ event })} options={events} />
                </div>
                <div>
                    <InputLabel htmlFor="wh_f_status" value="Estado" />
                    <Select id="wh_f_status" value={filters.status} onChange={(status) => change({ status })} options={STATUS_OPTIONS} />
                </div>
                <DateField id="wh_f_from" label="Desde" value={filters.from} max={filters.to || undefined} onChange={(from) => change({ from: from ?? '' })} />
                <DateField id="wh_f_to" label="Hasta" value={filters.to} min={filters.from || undefined} onChange={(to) => change({ to: to ?? '' })} />
            </div>
            {filtered && <SecondaryButton type="button" onClick={() => onFilters(EMPTY_FILTERS)}>Limpiar filtros</SecondaryButton>}

            {failed && <p role="alert" className="text-sm text-danger">No se pudo cargar el historial.</p>}
            {!failed && rows === undefined && <p className="text-sm text-ink-muted">Cargando…</p>}
            {rows?.length === 0 && <p className="text-sm text-ink-muted">{filtered ? 'Ninguna entrega coincide con los filtros.' : 'Todavía no hay entregas. Usa «Enviar prueba» para generar la primera.'}</p>}

            {rows?.length > 0 && (
                <div className="overflow-hidden rounded-xl border border-line bg-card">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[48rem] text-left text-sm">
                            <caption className="sr-only">Entregas de webhooks</caption>
                            <thead>
                                <tr className="border-b border-line text-xs tracking-wider text-ink-muted uppercase">
                                    <th scope="col" className="px-4 py-2 font-semibold">Evento</th>
                                    <th scope="col" className="py-2 pr-3 font-semibold">Webhook</th>
                                    <th scope="col" className="py-2 pr-3 font-semibold">Estado</th>
                                    <th scope="col" className="py-2 pr-3 font-semibold">Intentos</th>
                                    <th scope="col" className="py-2 pr-3 font-semibold">Resultado</th>
                                    <th scope="col" className="py-2 pr-3 font-semibold">Fecha</th>
                                    <th scope="col" className="py-2 pr-4 font-semibold"><span className="sr-only">Acciones</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((delivery) => {
                                    const state = STATUS[delivery.status] ?? STATUS.pending;

                                    return (
                                        <tr key={delivery.id} className="border-b border-line align-top last:border-0">
                                            <td className="px-4 py-3">
                                                <code className="font-mono text-[13px] text-ink">{delivery.event}</code>
                                                <span className="mt-0.5 block font-mono text-xs break-all text-ink-muted" title="Identificador del evento (X-Ava-Event-Id)">{delivery.eventId}</span>
                                            </td>
                                            <td className="py-3 pr-3 text-ink">{delivery.webhookName}</td>
                                            <td className="py-3 pr-3">
                                                <Badge tone={state.tone}>{state.label}</Badge>
                                                {delivery.nextAttemptAt && <span className="mt-1 block text-xs text-ink-muted">Próximo intento: {delivery.nextAttemptAt}</span>}
                                            </td>
                                            <td className="py-3 pr-3 text-ink">{delivery.attempts}</td>
                                            <td className="py-3 pr-3 text-ink">
                                                {reason(delivery)}
                                                {delivery.durationMs !== null && <span className="block text-xs text-ink-muted">{delivery.durationMs} ms</span>}
                                            </td>
                                            <td className="py-3 pr-3 text-ink-muted">{delivery.lastAttemptAt ?? delivery.createdAt}</td>
                                            <td className="py-3 pr-4 text-right">
                                                {canRetry && delivery.canRetry && (
                                                    <SecondaryButton type="button" onClick={() => retry(delivery)} className="gap-2">
                                                        <RotateCw className="size-4" aria-hidden="true" />
                                                        Reintentar
                                                    </SecondaryButton>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <Pagination meta={data.meta} onPage={setPage} noun="entregas" />
                </div>
            )}
        </div>
    );
}
