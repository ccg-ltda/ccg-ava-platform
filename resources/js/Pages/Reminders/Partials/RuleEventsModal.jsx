import Badge from '@/Components/Badge';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import usePagedFetch from '@/Hooks/usePagedFetch';

const STATUSES = {
    scheduled: { tone: 'blue', label: 'Programado' },
    processing: { tone: 'blue', label: 'Enviando' },
    sent: { tone: 'green', label: 'Enviado' },
    failed: { tone: 'red', label: 'Falló' },
    uncertain: { tone: 'amber', label: 'Sin confirmar' },
    skipped: { tone: 'amber', label: 'Omitido' },
    cancelled: { tone: 'neutral', label: 'Cancelado' },
};

/** What a rule decided for each appointment: who, which moment, the state of the reminder and the fixed reason when it was not scheduled or not sent. Read only. */
export default function RuleEventsModal({ rule, onClose }) {
    const { rows, meta, failed, loading, onPage } = usePagedFetch({ url: route('reminders.rules.events', rule.id), section: 'events', read: (body) => body.events });

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="truncate text-lg font-bold text-ink">Historial de «{rule.name}»</h2>
                    <p className="mt-1 text-sm text-ink-muted">Las citas realizadas que la regla evaluó, de la más reciente a la más antigua. Una cita omitida por falta de consentimiento, ficha o canal se vuelve a evaluar sola mientras siga a tiempo.</p>
                </header>
                <div className={`min-h-0 flex-1 overflow-auto px-5 py-4 sm:px-8 ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                    {failed && <p role="alert" className="text-sm text-danger">No se pudo cargar el historial.</p>}
                    {!failed && rows === null && <p className="text-sm text-ink-muted">Cargando…</p>}
                    {rows?.length === 0 && <p className="text-sm text-ink-muted">Todavía no hay citas realizadas que la regla haya evaluado.</p>}
                    {rows?.length > 0 && (
                        <ul className="divide-y divide-line">
                            {rows.map((row) => {
                                const status = STATUSES[row.status] ?? { tone: 'neutral', label: row.status };

                                return (
                                    <li key={row.id} className="flex flex-wrap items-start justify-between gap-2 py-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-ink">{row.customer ?? 'Sin ficha de cliente'} · cita #{row.appointmentId}</p>
                                            <p className="text-xs text-ink-muted">
                                                Evaluada {row.at}
                                                {row.sendAt && ` · envío ${row.sendAt}`}
                                            </p>
                                            {row.reason && <p className="mt-1 text-sm text-ink">{row.reason}</p>}
                                        </div>
                                        <Badge tone={status.tone}>{status.label}</Badge>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
                <Pagination meta={meta} onPage={onPage} noun="citas evaluadas" />
                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
