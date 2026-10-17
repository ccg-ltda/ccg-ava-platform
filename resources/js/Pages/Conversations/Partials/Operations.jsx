import { CalendarCheck, ShoppingBag, Wrench } from 'lucide-react';

const KINDS = {
    appointment: { label: 'Cita', icon: CalendarCheck },
    order: { label: 'Pedido', icon: ShoppingBag },
};

/** How a traced tool call is read: only `succeeded` is a done operation; the rest never says it was. */
const TOOL_TONES = {
    succeeded: 'text-ink-muted',
    rejected: 'text-accent-amber',
    failed: 'text-danger',
    uncertain: 'text-danger',
    started: 'text-ink-muted',
};

/**
 * What was done from this conversation: the appointments and orders the assistant or a person tied to it with the state Ava has now, and the business tools
 * the assistant ran through Ava (what it asked and whether Ava did it, refused it or could not tell). Read only; the server sends only what the user may open.
 */
export default function Operations({ operations, tools }) {
    const hasOperations = operations?.length > 0;
    const hasTools = tools?.length > 0;

    if (!hasOperations && !hasTools) return null;

    return (
        <section aria-label="Operaciones de esta conversación" className="space-y-2 border-b border-line bg-card px-3 py-2">
            {hasOperations && (
                <div>
                    <h3 className="text-[11px] font-bold uppercase tracking-wider text-ink-muted">Operaciones de esta conversación</h3>
                    <ul className="mt-1.5 flex flex-wrap gap-1.5">
                        {operations.map((operation) => {
                            const kind = KINDS[operation.kind];
                            const Icon = kind.icon;

                            return (
                                <li key={`${operation.kind}-${operation.id}`} className="flex max-w-full items-start gap-1.5 rounded-2xl bg-canvas px-2.5 py-1 text-xs text-ink ring-1 ring-line">
                                    <Icon className="mt-0.5 size-3.5 shrink-0 text-accent-blue" aria-hidden="true" />
                                    <span className="min-w-0 break-words">
                                        <span className="font-semibold">{operation.title}</span>
                                        <span className="text-ink-muted">
                                            {' · '}
                                            {operation.statusLabel}
                                            {' · '}
                                            {operation.when}
                                            {operation.detail && ` · ${operation.detail}`}
                                        </span>
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}

            {hasTools && (
                <details className="group">
                    <summary className="cursor-pointer text-[11px] font-bold uppercase tracking-wider text-ink-muted outline-none focus-visible:outline-2 focus-visible:outline-primary">
                        Herramientas del asistente ({tools.length})
                    </summary>
                    <ul className="mt-1.5 space-y-1">
                        {tools.map((tool) => (
                            <li key={tool.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink">
                                <Wrench className="size-3.5 shrink-0 text-ink-muted" aria-hidden="true" />
                                <span className="font-semibold">{tool.label}</span>
                                <span className={TOOL_TONES[tool.status]}>{tool.statusLabel}</span>
                                {tool.replayed && <span className="text-ink-muted">· repetida</span>}
                                <span className="text-ink-muted">· {tool.when}</span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
