import { X } from 'lucide-react';
import Badge from '@/Components/Badge';
import IconButton from '@/Components/IconButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { actionTone } from '@/config/audit';

const TITLES = { created: 'Datos creados', updated: 'Cambios realizados', deleted: 'Datos eliminados' };
const titleOf = (action) => TITLES[action] ?? 'Detalle del evento';

function Info({ label, children, mono = false }) {
    return (
        <div className="min-w-0">
            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</dt>
            <dd className={`mt-1 text-sm break-words text-ink ${mono ? 'font-mono' : ''}`}>{children}</dd>
        </div>
    );
}

/** A value as it was stored; an empty one is shown as such instead of a blank cell. */
function Value({ value, tone }) {
    return (
        <p className={`rounded-lg border px-3 py-2 text-sm break-words ${tone}`}>
            {value ?? <span className="text-ink-muted italic">(vacío)</span>}
        </p>
    );
}

/** Only the fields that matter for the action: before/after for a change, the values for a creation or deletion. */
function Changes({ event }) {
    if (event.changes.length === 0) {
        return <p className="rounded-lg border border-dashed border-line bg-canvas/60 px-4 py-6 text-center text-sm text-ink-muted">No se registraron cambios relevantes.</p>;
    }

    if (event.action === 'created' || event.action === 'deleted') {
        const side = event.action === 'created' ? 'after' : 'before';

        return (
            <dl className="divide-y divide-line rounded-xl border border-line">
                {event.changes.map((change) => (
                    <div key={change.field} className="grid gap-1 px-4 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4">
                        <dt className="text-sm font-semibold text-ink">{change.field}</dt>
                        <dd className="text-sm break-words text-ink">{change[side]}</dd>
                    </div>
                ))}
            </dl>
        );
    }

    return (
        <ul className="space-y-3">
            {event.changes.map((change) => (
                <li key={change.field} className="rounded-xl border border-line p-4">
                    <p className="text-sm font-bold text-ink">{change.field}</p>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <p className="mb-1 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Antes</p>
                            <Value value={change.before} tone="border-danger/25 bg-danger/5" />
                        </div>
                        <div>
                            <p className="mb-1 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Después</p>
                            <Value value={change.after} tone="border-accent-green/30 bg-accent-green/5" />
                        </div>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** Detail of one audit event: general information first, then only what changed. */
export default function AuditDetailModal({ event, onClose }) {
    return (
        <Modal show={Boolean(event)} onClose={onClose} maxWidth="2xl" scrollable>
            {event && (
                <div className="flex min-h-0 flex-1 flex-col" role="document">
                    <header className="flex items-start justify-between gap-4 border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <Badge tone={actionTone(event.action)}>{event.actionLabel}</Badge>
                                {event.outcome === 'failed' && <Badge tone="red">Fallido</Badge>}
                            </div>
                            <h2 className="mt-2 text-lg font-bold text-ink">{event.description}</h2>
                        </div>
                        <IconButton icon={X} label="Cerrar" onClick={onClose} />
                    </header>

                    <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-6 sm:px-8">
                        <section aria-label="Información general" className="rounded-xl border border-line bg-canvas/40 p-5">
                            <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                                <Info label={event.actor === 'system' ? 'Proceso' : 'Usuario'}>
                                    {event.user.name}
                                    {event.user.email && <span className="block text-xs text-ink-muted">{event.user.email}</span>}
                                </Info>
                                <Info label="Workspace">
                                    {event.workspace.name}
                                    <span className="block font-mono text-xs text-ink-muted">{event.workspace.code}</span>
                                </Info>
                                <Info label="Origen">{event.actor === 'system' ? 'Automático (Ava)' : 'Una persona'}</Info>
                                <Info label="Resultado">{event.outcome === 'failed' ? 'Fallido' : 'Correcto'}</Info>
                                <Info label="Acción">{event.actionLabel}</Info>
                                <Info label="Módulo">{event.module}</Info>
                                <Info label="Registro">{event.record}</Info>
                                <Info label="IP" mono>
                                    {event.ip ?? 'No disponible'}
                                </Info>
                                <Info label="Fecha">{event.date}</Info>
                                <Info label="Hora" mono>
                                    {event.time}
                                </Info>
                            </dl>
                        </section>

                        <section aria-label={titleOf(event.action)}>
                            <h3 className="mb-3 text-sm font-bold tracking-wider text-accent-blue uppercase">{titleOf(event.action)}</h3>
                            <Changes event={event} />
                        </section>
                    </div>

                    <footer className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                        <SecondaryButton type="button" onClick={onClose}>
                            Cerrar
                        </SecondaryButton>
                    </footer>
                </div>
            )}
        </Modal>
    );
}
