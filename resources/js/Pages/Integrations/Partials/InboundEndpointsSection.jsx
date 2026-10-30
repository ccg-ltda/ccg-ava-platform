import axios from 'axios';
import { router, useForm } from '@inertiajs/react';
import { Copy, History, KeyRound, Pencil, Plus, Power, Trash2, Inbox } from 'lucide-react';
import { useEffect, useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import WebhookSecretModal from './WebhookSecretModal';

function NameModal({ endpoint, onClose }) {
    const form = useForm({ name: endpoint?.name ?? '' });
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        endpoint ? form.put(route('integrations.webhooks.inbound.update', endpoint.id), options) : form.post(route('integrations.webhooks.inbound.store'), options);
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} noValidate className="space-y-4 p-5 sm:p-6">
                <header>
                    <h2 className="text-lg font-bold text-ink">{endpoint ? 'Editar endpoint' : 'Crear endpoint'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Ava genera la dirección y el secreto de firma. Tu sistema envía a esa dirección eventos firmados.</p>
                </header>
                <div>
                    <InputLabel htmlFor="in_name" value="Nombre" />
                    <TextInput id="in_name" className="mt-1" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} invalid={Boolean(form.errors.name)} maxLength={80} autoComplete="off" />
                    <InputError message={form.errors.name} className="mt-1" />
                </div>
                <div className="flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>Cancelar</SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>{endpoint ? 'Guardar' : 'Crear endpoint'}</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function EventsModal({ endpoint, onClose }) {
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        axios.get(route('integrations.webhooks.inbound.events', endpoint.id), { params: { page } }).then((response) => setData(response.data)).catch(() => setFailed(true));
    }, [endpoint.id, page]);

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="truncate text-lg font-bold text-ink">Recepciones de «{endpoint.name}»</h2>
                    <p className="mt-1 text-sm text-ink-muted">Solo se guarda el identificador, el tipo, el tamaño y la hora de cada evento aceptado; nunca su contenido. Se conservan unos días.</p>
                </header>
                <div className="min-h-0 flex-1 overflow-auto px-5 py-4 sm:px-8">
                    {failed && <p role="alert" className="text-sm text-danger">No se pudo cargar el historial.</p>}
                    {!failed && data === null && <p className="text-sm text-ink-muted">Cargando…</p>}
                    {data?.events.length === 0 && <p className="text-sm text-ink-muted">Todavía no se ha recibido ningún evento.</p>}
                    {data?.events.length > 0 && (
                        <ul className="divide-y divide-line">
                            {data.events.map((event) => (
                                <li key={event.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                    <span className="min-w-0"><code className="font-mono text-xs break-all text-ink">{event.eventId}</code>{event.type && <span className="ml-2 text-ink-muted">{event.type}</span>}</span>
                                    <span className="text-xs text-ink-muted">{event.bytes} B · {event.at}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                {data && <Pagination meta={data.meta} onPage={setPage} noun="eventos" />}
                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8"><SecondaryButton type="button" onClick={onClose}>Cerrar</SecondaryButton></div>
            </div>
        </Modal>
    );
}

/**
 * Inbound endpoints (first section of the Webhooks tab): the URL generated by Ava that the customer's system calls with a signed event. It is the opposite direction of the
 * configured webhooks below (Ava calls the customer) and has its OWN secret. Today an accepted event is recorded in the history (type, size, time) and nothing else happens.
 */
export default function InboundEndpointsSection({ inbound, newSecret, can }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null);
    const [events, setEvents] = useState(null);
    const [dismissed, setDismissed] = useState(null);
    const secret = newSecret && dismissed !== newSecret.secret ? newSecret : null;
    const post = (name, id) => router.post(route(name, id), {}, { preserveScroll: true });

    const toggle = async (endpoint) => {
        const confirmed = await confirm({
            description: endpoint.isActive ? `La dirección de «${endpoint.name}» dejará de aceptar eventos (responde 404) hasta que lo actives.` : `«${endpoint.name}» volverá a aceptar eventos firmados.`,
            confirmLabel: endpoint.isActive ? 'Desactivar' : 'Activar',
        });

        if (confirmed) post(endpoint.isActive ? 'integrations.webhooks.inbound.deactivate' : 'integrations.webhooks.inbound.activate', endpoint.id);
    };
    const rotate = async (endpoint) => {
        if (await confirm({ description: `Se generará un secreto nuevo para «${endpoint.name}» y se mostrará una sola vez. El anterior se sigue aceptando durante ${inbound.graceHours} h.`, confirmLabel: 'Rotar secreto' })) post('integrations.webhooks.inbound.rotate', endpoint.id);
    };
    const remove = async (endpoint) => {
        if (await confirm({ description: `«${endpoint.name}» se eliminará con su historial y su dirección dejará de existir. No se puede deshacer.`, confirmLabel: 'Eliminar endpoint' })) router.delete(route('integrations.webhooks.inbound.destroy', endpoint.id), { preserveScroll: true });
    };

    return (
        <section aria-labelledby="inbound-heading" className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="inbound-heading" className="text-lg font-bold text-ink">Endpoints entrantes</h2>
                    <p className="text-sm text-ink-muted">Direcciones que Ava genera para que TU sistema le envíe eventos firmados. No son los avisos que Ava te envía: esos están más abajo.</p>
                </div>
                {inbound.enabled && can.manage && inbound.endpoints.length < inbound.maxEndpoints && (
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Crear endpoint
                    </PrimaryButton>
                )}
            </div>

            {!inbound.enabled ? (
                <Alert tone="info">Los endpoints entrantes todavía no están disponibles en este entorno. Se activan cuando se aplica la actualización de base de datos correspondiente.</Alert>
            ) : (
                <>
                    <details className="rounded-xl border border-line bg-card p-4 text-sm text-ink-muted">
                        <summary className="cursor-pointer font-semibold text-ink">Cómo enviar eventos a Ava</summary>
                        <div className="mt-3 space-y-2">
                            <p>Haz un <strong className="text-ink">POST</strong> con un objeto JSON a la dirección del endpoint, con estos encabezados:</p>
                            <ul className="list-disc space-y-1 pl-5">
                                <li><code className="font-mono text-ink">X-Ava-Event-Id</code>: identificador único del evento (8 a 64 caracteres). Si lo repites, Ava responde 200 «duplicate» y no lo registra otra vez.</li>
                                <li><code className="font-mono text-ink">X-Ava-Timestamp</code>: segundos Unix; se rechaza si difiere más de {inbound.toleranceSeconds / 60} min.</li>
                                <li><code className="font-mono text-ink">X-Ava-Signature</code>: <code className="font-mono text-ink">sha256=</code> + HMAC-SHA256 hexadecimal de <code className="font-mono text-ink">timestamp + &quot;.&quot; + cuerpo</code> con el secreto del endpoint.</li>
                            </ul>
                            <p>Respuestas: <strong className="text-ink">202</strong> aceptado, <strong className="text-ink">200</strong> duplicado, <strong className="text-ink">401</strong> firma inválida, <strong className="text-ink">422</strong> cuerpo o identificador inválidos, <strong className="text-ink">404</strong> endpoint inexistente o desactivado. Hoy Ava <strong className="text-ink">registra</strong> el evento (tipo, tamaño y hora) y no ejecuta ninguna acción con su contenido, que no se guarda.</p>
                        </div>
                    </details>

                    {inbound.endpoints.length === 0 ? (
                        <EmptyState icon={Inbox} title="Todavía no hay endpoints entrantes" description="Crea uno para obtener la dirección y el secreto con los que tu sistema enviará eventos a Ava.">
                            {can.manage && <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2"><Plus className="size-4" aria-hidden="true" />Crear endpoint</PrimaryButton>}
                        </EmptyState>
                    ) : (
                        <div className="grid gap-6 md:grid-cols-2">
                            {inbound.endpoints.map((endpoint, index) => (
                                <Card key={endpoint.id} hover delay={index * 50} className={`flex min-w-0 flex-col p-5 ${endpoint.isActive ? '' : 'bg-canvas/40'}`}>
                                    <div className="flex items-start gap-3">
                                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue"><Inbox className="size-5" aria-hidden="true" /></span>
                                        <div className="min-w-0 flex-1">
                                            <h3 className="truncate text-base font-bold text-ink" title={endpoint.name}>{endpoint.name}</h3>
                                            <p className="text-xs text-ink-muted">Firma requerida</p>
                                        </div>
                                        <Badge tone={endpoint.isActive ? 'green' : 'neutral'}>{endpoint.isActive ? 'Activo' : 'Inactivo'}</Badge>
                                    </div>
                                    <div className="mt-3"><CopyBlock value={endpoint.url} label={`Dirección de ${endpoint.name}`} message="Dirección copiada." /></div>
                                    <dl className="mt-3 space-y-1.5 text-sm">
                                        <div className="flex justify-between gap-3"><dt className="text-ink-muted">Secreto</dt><dd className="font-mono text-[13px] text-ink">whin_••••{endpoint.secretHint}</dd></div>
                                        <div className="flex justify-between gap-3"><dt className="text-ink-muted">Recibidos / rechazados</dt><dd className="text-ink">{endpoint.received} / {endpoint.rejected}</dd></div>
                                        <div className="flex justify-between gap-3"><dt className="text-ink-muted">Última recepción</dt><dd className="text-ink">{endpoint.lastReceivedAt ?? '—'}</dd></div>
                                    </dl>
                                    {endpoint.graceUntil && <p className="mt-2 text-xs text-ink-muted">Secreto anterior aceptado hasta {endpoint.graceUntil}.</p>}
                                    <div className="mt-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-line pt-3">
                                        <p className="min-w-0 truncate text-xs text-ink-muted">Creado {endpoint.createdAt}</p>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <IconButton icon={History} label="Ver recepciones" context={endpoint.name} onClick={() => setEvents(endpoint)} />
                                            <IconButton icon={Copy} label="Copiar dirección" context={endpoint.name} onClick={() => navigator.clipboard?.writeText(endpoint.url)} />
                                            {can.manage && (
                                                <>
                                                    <IconButton icon={Pencil} label="Editar" context={endpoint.name} onClick={() => setEditing(endpoint)} />
                                                    <IconButton icon={KeyRound} label="Rotar secreto" context={endpoint.name} onClick={() => rotate(endpoint)} />
                                                    <IconButton icon={Power} label={endpoint.isActive ? 'Desactivar' : 'Activar'} context={endpoint.name} tone={endpoint.isActive ? 'danger' : 'primary'} onClick={() => toggle(endpoint)} />
                                                    <IconButton icon={Trash2} label="Eliminar" context={endpoint.name} tone="danger" onClick={() => remove(endpoint)} />
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </Card>
                            ))}
                        </div>
                    )}
                </>
            )}

            {editing && <NameModal key={editing === 'new' ? 'new' : editing.id} endpoint={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {events && <EventsModal endpoint={events} onClose={() => setEvents(null)} />}
            {secret && <WebhookSecretModal secret={secret} graceHours={inbound.graceHours} inbound onClose={() => setDismissed(secret.secret)} />}
        </section>
    );
}
