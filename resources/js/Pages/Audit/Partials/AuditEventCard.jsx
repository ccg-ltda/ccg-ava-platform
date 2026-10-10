import { Bot, ChevronRight, Globe } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { actionTone, auditActions } from '@/config/audit';

const initials = (name = '') =>
    name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');

/** Names of the changed fields, as a short hint of what the event touched. */
const touched = (changes) => {
    const names = changes.map((change) => change.field);

    return names.length > 3 ? `${names.slice(0, 3).join(', ')} y ${names.length - 3} más` : names.join(', ');
};

/** One audit event: what happened, who did it, on which record, when and from where. Opens the detail. */
export default function AuditEventCard({ event, onOpen, delay = 0 }) {
    const { action, actionLabel, actor, outcome, user, workspace, module, record, date, time, ip, changes } = event;

    return (
        <Card as="li" hover delay={delay} className="relative list-none overflow-hidden">
            <span className={`absolute inset-y-0 left-0 w-1 ${auditActions[action]?.bar}`} aria-hidden="true" />
            <button
                type="button"
                onClick={onOpen}
                aria-label={`Ver detalle: ${actionLabel} ${module} ${record}`}
                className="grid w-full gap-3 py-4 pr-4 pl-6 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary md:grid-cols-[11rem_minmax(0,1fr)_minmax(0,1.2fr)_auto] md:items-center md:gap-5"
            >
                <div className="flex items-center justify-between gap-3 md:block">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge tone={actionTone(action)}>{actionLabel}</Badge>
                        {outcome === 'failed' && <Badge tone="red">Fallido</Badge>}
                    </div>
                    <p className="text-sm font-semibold text-ink md:mt-2">{date}</p>
                    <p className="font-mono text-xs text-ink-muted md:mt-0.5">{time}</p>
                </div>

                <div className="flex min-w-0 items-center gap-3">
                    <span className="grid size-9 shrink-0 place-items-center rounded-full bg-accent-blue/10 text-xs font-bold text-accent-blue">
                        {actor === 'system' ? <Bot className="size-4" aria-label="Proceso automático" /> : initials(user.name)}
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-ink">{user.name}</p>
                        <p className="truncate text-xs text-ink-muted">
                            {actor === 'system' ? 'Automático · ' : ''}
                            {workspace.name}
                        </p>
                    </div>
                </div>

                <div className="min-w-0">
                    <p className="truncate text-sm text-ink">
                        <span className="font-semibold">{module}</span>
                        <span className="px-1.5 text-ink-muted" aria-hidden="true">
                            ›
                        </span>
                        {record}
                    </p>
                    <p className="mt-1 flex items-center gap-1.5 text-xs text-ink-muted">
                        <Globe className="size-3.5 shrink-0" aria-hidden="true" />
                        <span className="font-mono">{ip ?? 'IP no disponible'}</span>
                    </p>
                    {changes.length > 0 && <p className="mt-1 truncate text-xs text-ink-muted">{{ updated: 'Cambió', created: 'Datos', deleted: 'Tenía' }[action] ?? 'Detalle'}: {touched(changes)}</p>}
                </div>

                <span className="hidden items-center gap-1 text-xs font-semibold tracking-wider text-primary uppercase md:inline-flex">
                    Detalle
                    <ChevronRight className="size-4" aria-hidden="true" />
                </span>
            </button>
        </Card>
    );
}
