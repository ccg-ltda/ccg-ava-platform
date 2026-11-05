import Badge from '@/Components/Badge';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import usePagedFetch from '@/Hooks/usePagedFetch';

const OUTCOMES = {
    sent: { tone: 'green', label: 'Enviado' },
    retry: { tone: 'amber', label: 'Se reintentará' },
    failed: { tone: 'red', label: 'Falló' },
    skipped: { tone: 'amber', label: 'Omitido' },
    cancelled: { tone: 'neutral', label: 'Cancelado' },
    uncertain: { tone: 'amber', label: 'Sin confirmar' },
};

/** The tries of one reminder: when, what happened and why (a fixed reason, never provider text). */
export default function ReminderAttemptsModal({ reminder, onClose }) {
    const { rows, meta, failed, loading, onPage } = usePagedFetch({ url: route('reminders.attempts', reminder.id), section: 'attempts', read: (body) => body.attempts });

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="truncate text-lg font-bold text-ink">Intentos de «{reminder.name}»</h2>
                    <p className="mt-1 text-sm text-ink-muted">Un recordatorio que pudo haberse enviado y no se confirmó nunca se reenvía solo.</p>
                </header>
                <div className={`min-h-0 flex-1 overflow-auto px-5 py-4 sm:px-8 ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                    {failed && <p role="alert" className="text-sm text-danger">No se pudo cargar el historial.</p>}
                    {!failed && rows === null && <p className="text-sm text-ink-muted">Cargando…</p>}
                    {rows?.length === 0 && <p className="text-sm text-ink-muted">Todavía no hay intentos: el recordatorio espera su fecha.</p>}
                    {rows?.length > 0 && (
                        <ul className="divide-y divide-line">
                            {rows.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-start justify-between gap-2 py-3">
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-ink">Intento {row.attempt}</p>
                                        <p className="text-xs text-ink-muted">{row.at}</p>
                                        {row.reason && <p className="mt-1 text-sm text-ink">{row.reason}</p>}
                                        {row.detail && <p className="mt-0.5 break-words font-mono text-xs text-ink-muted">{row.detail}</p>}
                                    </div>
                                    <Badge tone={(OUTCOMES[row.outcome] ?? OUTCOMES.failed).tone}>{(OUTCOMES[row.outcome] ?? { label: row.outcome }).label}</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <Pagination meta={meta} onPage={onPage} noun="intentos" />
                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
