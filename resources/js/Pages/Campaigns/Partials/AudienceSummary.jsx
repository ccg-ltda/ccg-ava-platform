import { Users } from 'lucide-react';

/** Totals of the audience: how many customers the campaign points at, how many it would reach and who is left out and why. */
export default function AudienceSummary({ summary, exclusions, frozen }) {
    const excluded = Object.entries(summary.excluded);

    return (
        <div className="rounded-lg border border-line bg-canvas/60 p-4">
            <p className="flex items-center gap-2 text-sm font-bold text-ink">
                <Users className="size-4" aria-hidden="true" />
                {frozen ? 'Destinatarios fijados' : 'Revisión de destinatarios'}
            </p>
            <dl className="mt-3 grid grid-cols-3 gap-3 text-center">
                <div>
                    <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Seleccionados</dt>
                    <dd className="text-2xl font-extrabold text-ink">{summary.total}</dd>
                </div>
                <div>
                    <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Recibirían</dt>
                    <dd className="text-2xl font-extrabold text-accent-green">{summary.eligible}</dd>
                </div>
                <div>
                    <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Excluidos</dt>
                    <dd className="text-2xl font-extrabold text-accent-amber">{summary.total - summary.eligible}</dd>
                </div>
            </dl>
            {excluded.length > 0 && (
                <ul className="mt-3 space-y-1 border-t border-line pt-3 text-sm text-ink-muted">
                    {excluded.map(([reason, count]) => (
                        <li key={reason} className="flex justify-between gap-3">
                            <span>{exclusions[reason] ?? reason}</span>
                            <span className="font-semibold text-ink">{count}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
