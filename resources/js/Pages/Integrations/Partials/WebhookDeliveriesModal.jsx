import axios from 'axios';
import { router } from '@inertiajs/react';
import { RotateCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import Badge from '@/Components/Badge';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';

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

/**
 * Delivery history of one webhook: what was sent (event, ids, attempts, result), never the body or any secret. A failed or abandoned delivery can be retried; it is
 * re-sent with the same event id, so a consumer that deduplicates by it processes it once.
 */
export default function WebhookDeliveriesModal({ webhook, canRetry, onClose }) {
    const [rows, setRows] = useState(null);
    const [failed, setFailed] = useState(false);

    const load = useCallback(() => {
        axios.get(route('integrations.webhooks.deliveries', webhook.id)).then((response) => setRows(response.data.deliveries)).catch(() => setFailed(true));
    }, [webhook.id]);

    useEffect(load, [load]);

    const retry = (delivery) => router.post(route('integrations.webhooks.retry', [webhook.id, delivery.id]), {}, { preserveScroll: true, onFinish: () => setTimeout(load, 600) });

    return (
        <Modal show onClose={onClose} maxWidth="5xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-8">
                    <div className="min-w-0">
                        <h2 className="truncate text-lg font-bold text-ink">Entregas de «{webhook.name}»</h2>
                        <p className="mt-1 text-sm text-ink-muted">Las últimas 50. Se conservan unos días; el contenido enviado no se guarda aquí.</p>
                    </div>
                    <SecondaryButton type="button" onClick={load} className="shrink-0 gap-2">
                        <RotateCw className="size-4" aria-hidden="true" />
                        Actualizar
                    </SecondaryButton>
                </header>

                <div className="min-h-0 flex-1 overflow-auto px-5 py-4 sm:px-8">
                    {failed && <p role="alert" className="text-sm text-danger">No se pudo cargar el historial.</p>}
                    {!failed && rows === null && <p className="text-sm text-ink-muted">Cargando…</p>}
                    {rows?.length === 0 && <p className="text-sm text-ink-muted">Todavía no hay entregas. Usa «Enviar prueba» para generar la primera.</p>}

                    {rows?.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[44rem] text-left text-sm">
                                <caption className="sr-only">Entregas del webhook {webhook.name}</caption>
                                <thead>
                                    <tr className="border-b border-line text-xs tracking-wider text-ink-muted uppercase">
                                        <th scope="col" className="py-2 pr-3 font-semibold">Evento</th>
                                        <th scope="col" className="py-2 pr-3 font-semibold">Estado</th>
                                        <th scope="col" className="py-2 pr-3 font-semibold">Intentos</th>
                                        <th scope="col" className="py-2 pr-3 font-semibold">Resultado</th>
                                        <th scope="col" className="py-2 pr-3 font-semibold">Fecha</th>
                                        <th scope="col" className="py-2 font-semibold"><span className="sr-only">Acciones</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((delivery) => {
                                        const state = STATUS[delivery.status] ?? STATUS.pending;

                                        return (
                                            <tr key={delivery.id} className="border-b border-line align-top last:border-0">
                                                <td className="py-3 pr-3">
                                                    <code className="font-mono text-[13px] text-ink">{delivery.event}</code>
                                                    <span className="mt-0.5 block font-mono text-xs break-all text-ink-muted" title="Identificador del evento (X-Ava-Event-Id)">{delivery.eventId}</span>
                                                </td>
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
                                                <td className="py-3 text-right">
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
                    )}
                </div>

                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cerrar
                    </SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
