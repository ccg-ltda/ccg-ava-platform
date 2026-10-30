import { router } from '@inertiajs/react';
import { Copy, History, KeyRound, Pencil, Plus, Power, Send, Trash2, Webhook } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import WebhookHistory, { EMPTY_FILTERS } from './WebhookHistory';
import WebhookFormModal from './WebhookFormModal';
import WebhookSecretModal from './WebhookSecretModal';

const host = (url) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};

const state = (webhook) => {
    if (webhook.isActive) return { tone: 'green', label: 'Activo' };
    if (webhook.disabledReason === 'failures') return { tone: 'red', label: 'Desactivado por fallos' };

    return { tone: 'neutral', label: 'Inactivo' };
};

function HowItWorks({ limits }) {
    return (
        <details className="rounded-xl border border-line bg-card p-4 text-sm text-ink-muted">
            <summary className="cursor-pointer font-semibold text-ink">Cómo recibir los avisos en tu sistema</summary>
            <div className="mt-3 space-y-3">
                <p>
                    Ava envía un <strong className="text-ink">POST</strong> con un JSON a tu dirección. Responde con cualquier código <strong className="text-ink">2xx</strong> en menos de {limits.timeoutSeconds} s; cualquier otra respuesta se reintenta hasta {limits.attempts} veces en total con esperas crecientes. Estos avisos son de salida: los de WhatsApp (Meta) y Wompi los recibe Ava y se configuran en sus propias tarjetas.
                </p>
                <ul className="list-disc space-y-1 pl-5">
                    <li>
                        <code className="font-mono text-ink">X-Ava-Event-Id</code>: identificador único del evento. Es el mismo en cada reintento: <strong className="text-ink">guárdalo y descarta los repetidos</strong>. La entrega es «al menos una vez».
                    </li>
                    <li>
                        <code className="font-mono text-ink">X-Ava-Timestamp</code> y <code className="font-mono text-ink">X-Ava-Signature</code>: la firma es <code className="font-mono text-ink">sha256=</code> + HMAC-SHA256 en hexadecimal de <code className="font-mono text-ink">{'{timestamp}.{cuerpo}'}</code> con tu secreto. Compárala con tiempo constante y rechaza marcas de tiempo con más de 5 minutos.
                    </li>
                    <li>
                        <code className="font-mono text-ink">X-Ava-Event</code>, <code className="font-mono text-ink">X-Ava-Delivery</code> y <code className="font-mono text-ink">X-Ava-Delivery-Attempt</code>: tipo de evento, identificador de la entrega y número de intento.
                    </li>
                    <li>No hay garantía de orden entre eventos: usa los identificadores y consulta el estado actual en Ava si lo necesitas.</li>
                </ul>
                <pre className="overflow-x-auto rounded-lg border border-line bg-canvas p-3 font-mono text-[13px] text-ink">{`const esperado = 'sha256=' + hmacSha256Hex(secreto, timestamp + '.' + cuerpoCrudo);
if (!comparacionSegura(esperado, cabeceraSignature)) rechazar();`}</pre>
                <p>Usa el cuerpo exactamente como llega (sin volver a serializarlo). Tras rotar el secreto, durante {limits.graceHours} h también llega <code className="font-mono text-ink">X-Ava-Signature-Previous</code> firmado con el secreto anterior.</p>
            </div>
        </details>
    );
}

/** Webhooks de salida: the destinations a Workspace registers to be told about what happens in Ava. Lives inside Integraciones; the server decides what each role may do. */
export default function WebhooksSection({ webhooks, newSecret, can }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null); // null = closed, 'new' = creating, otherwise the webhook
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [dismissed, setDismissed] = useState(null);
    const secret = newSecret && dismissed !== newSecret.secret ? newSecret : null;
    const showDeliveries = (webhook) => {
        setFilters({ ...EMPTY_FILTERS, webhook: String(webhook.id) });
        document.getElementById('webhook-history')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    const copyUrl = (webhook) => navigator.clipboard?.writeText(webhook.url);
    const post = (name, id) => router.post(route(name, id), {}, { preserveScroll: true });

    const toggle = async (webhook) => {
        const deactivating = webhook.isActive;
        const confirmed = await confirm({
            description: deactivating ? `«${webhook.name}» dejará de recibir avisos hasta que lo actives. Los que ocurran mientras tanto no se envían después.` : `«${webhook.name}» volverá a recibir los avisos que elegiste.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (confirmed) post(deactivating ? 'integrations.webhooks.deactivate' : 'integrations.webhooks.activate', webhook.id);
    };

    const rotate = async (webhook) => {
        const confirmed = await confirm({
            description: `Se generará un secreto nuevo para «${webhook.name}» y se mostrará una sola vez. El anterior sigue firmando un segundo encabezado durante ${webhooks.limits.graceHours} h para que lo cambies sin perder avisos.`,
            confirmLabel: 'Rotar secreto',
        });

        if (confirmed) post('integrations.webhooks.rotate', webhook.id);
    };

    const remove = async (webhook) => {
        const confirmed = await confirm({
            description: `«${webhook.name}» se eliminará junto con su historial de entregas. No se puede deshacer; la Auditoría conserva el registro.`,
            confirmLabel: 'Eliminar webhook',
        });

        if (confirmed) router.delete(route('integrations.webhooks.destroy', webhook.id), { preserveScroll: true });
    };

    if (!webhooks.enabled) {
        return (
            <section aria-labelledby="webhooks-heading" className="space-y-4">
                <div>
                    <h2 id="webhooks-heading" className="text-lg font-bold text-ink">Webhooks</h2>
                </div>
                <Alert tone="info">Los webhooks todavía no están disponibles en este entorno. Se activan cuando se aplica la actualización de base de datos correspondiente.</Alert>
            </section>
        );
    }

    return (
        <section aria-labelledby="webhooks-heading" className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="webhooks-heading" className="text-lg font-bold text-ink">Webhooks configurados</h2>
                    <p className="text-sm text-ink-muted">Avisos firmados que Ava ENVÍA a la dirección de tu sistema cuando ocurre algo: conversaciones, reservas y pedidos.</p>
                </div>
                {can.manage && webhooks.endpoints.length < webhooks.limits.maxEndpoints && (
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Crear webhook
                    </PrimaryButton>
                )}
            </div>

            <HowItWorks limits={webhooks.limits} />

            <h3 className="text-base font-bold text-ink">Destinos</h3>
            {webhooks.endpoints.length === 0 ? (
                <EmptyState icon={Webhook} title="Todavía no hay webhooks" description="Crea el primero para que tu sistema se entere en el momento en que llega un mensaje, se agenda una cita o entra un pedido.">
                    {can.manage && (
                        <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                            <Plus className="size-4" aria-hidden="true" />
                            Crear webhook
                        </PrimaryButton>
                    )}
                </EmptyState>
            ) : (
                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {webhooks.endpoints.map((webhook, index) => {
                        const status = state(webhook);

                        return (
                            <Card key={webhook.id} hover delay={index * 50} className={`flex min-w-0 flex-col p-5 ${webhook.isActive ? '' : 'bg-canvas/40'}`}>
                                <div className="flex items-start gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                                        <Webhook className="size-5" aria-hidden="true" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <h3 className="truncate text-base font-bold text-ink" title={webhook.name}>{webhook.name}</h3>
                                        <p className="truncate font-mono text-[13px] text-ink-muted" title={host(webhook.url)}>{host(webhook.url)}</p>
                                    </div>
                                    <Badge tone={status.tone}>{status.label}</Badge>
                                </div>

                                {!webhook.isActive && webhook.disabledReason === 'failures' && (
                                    <Alert tone="warning" className="mt-3">Se desactivó por fallos seguidos. Corrige el destino, actívalo y reintenta las entregas fallidas.</Alert>
                                )}

                                <dl className="mt-4 space-y-1.5 text-sm">
                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-ink-muted">Eventos</dt>
                                        <dd className="font-semibold text-ink">{webhook.events.length}</dd>
                                    </div>
                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-ink-muted">Secreto</dt>
                                        <dd className="font-mono text-[13px] text-ink">whsec_••••{webhook.secretHint}</dd>
                                    </div>
                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-ink-muted">Última entrega correcta</dt>
                                        <dd className="text-ink">{webhook.lastSuccessAt ?? '—'}</dd>
                                    </div>
                                    {webhook.consecutiveFailures > 0 && (
                                        <div className="flex items-center justify-between gap-3">
                                            <dt className="text-ink-muted">Fallos seguidos</dt>
                                            <dd className="font-semibold text-danger">{webhook.consecutiveFailures}</dd>
                                        </div>
                                    )}
                                </dl>

                                <ul className="mt-3 flex flex-wrap gap-1.5" aria-label="Eventos suscritos">
                                    {webhook.events.slice(0, 4).map((event) => (
                                        <li key={event}><code className="rounded bg-canvas px-1.5 py-0.5 font-mono text-xs text-ink-muted ring-1 ring-line">{event}</code></li>
                                    ))}
                                    {webhook.events.length > 4 && <li className="text-xs text-ink-muted">+{webhook.events.length - 4} más</li>}
                                </ul>

                                {webhook.graceUntil && <p className="mt-3 text-xs text-ink-muted">Secreto anterior vigente hasta {webhook.graceUntil}.</p>}

                                <div className="mt-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-line pt-3">
                                    <p className="min-w-0 truncate text-xs text-ink-muted">Creado {webhook.createdAt}</p>
                                    <div className="flex flex-wrap items-center gap-1">
                                        <IconButton icon={History} label="Ver entregas" context={webhook.name} onClick={() => showDeliveries(webhook)} />
                                        <IconButton icon={Copy} label="Copiar dirección" context={webhook.name} onClick={() => copyUrl(webhook)} />
                                        {can.manage && (
                                            <>
                                                <IconButton icon={Send} label="Enviar prueba" context={webhook.name} onClick={() => post('integrations.webhooks.test', webhook.id)} disabled={!webhook.isActive} />
                                                <IconButton icon={Pencil} label="Editar" context={webhook.name} onClick={() => setEditing(webhook)} />
                                                <IconButton icon={KeyRound} label="Rotar secreto" context={webhook.name} onClick={() => rotate(webhook)} />
                                                <IconButton icon={Power} label={webhook.isActive ? 'Desactivar' : 'Activar'} context={webhook.name} tone={webhook.isActive ? 'danger' : 'primary'} onClick={() => toggle(webhook)} />
                                                <IconButton icon={Trash2} label="Eliminar" context={webhook.name} tone="danger" onClick={() => remove(webhook)} />
                                            </>
                                        )}
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            <WebhookHistory webhooks={webhooks} filters={filters} onFilters={setFilters} canRetry={can.manage} />

            {editing && <WebhookFormModal key={editing === 'new' ? 'new' : editing.id} webhook={editing === 'new' ? null : editing} events={webhooks.events} onClose={() => setEditing(null)} />}
            {secret && <WebhookSecretModal secret={secret} graceHours={webhooks.limits.graceHours} onClose={() => setDismissed(secret.secret)} />}
        </section>
    );
}
