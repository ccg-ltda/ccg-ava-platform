import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import Card from '@/Components/Card';
import useCountUp from '@/Hooks/useCountUp';

const TONES = {
    blue: { border: 'border-t-primary', icon: 'bg-primary/15 text-accent-blue' },
    violet: { border: 'border-t-accent-violet', icon: 'bg-accent-violet/10 text-accent-violet' },
    green: { border: 'border-t-accent-green', icon: 'bg-accent-green/10 text-accent-green' },
    amber: { border: 'border-t-accent-amber', icon: 'bg-accent-amber/10 text-accent-amber' },
};

/** Change against the previous period: a real percentage, or the plain fact that there is nothing to compare with. */
function Change({ change, previous }) {
    if (change === undefined) return null;

    if (change === null) return <span className="text-xs text-ink-muted">{previous === 0 ? 'Sin periodo anterior para comparar' : ''}</span>;

    const Icon = change > 0 ? ArrowUpRight : change < 0 ? ArrowDownRight : Minus;
    const color = change > 0 ? 'text-accent-green' : change < 0 ? 'text-danger' : 'text-ink-muted';

    return (
        <span className={`inline-flex items-center gap-0.5 text-xs font-semibold ${color}`} title={`Periodo anterior: ${previous.toLocaleString('es')}`}>
            <Icon className="size-3.5" aria-hidden="true" />
            {change > 0 ? '+' : ''}
            {change}%<span className="sr-only"> frente al periodo anterior</span>
        </span>
    );
}

/**
 * One headline figure of the Dashboard. The number counts up once when it appears; `change` (percentage or null) and
 * `previous` come from the server. With `href` the whole card is a link to the page that handles it.
 */
export default function KpiCard({ label, value, hint, icon: Icon, tone = 'blue', change, previous = 0, href, delay = 0, emptyLabel, format = (number) => number.toLocaleString('es') }) {
    const colors = TONES[tone];
    const shown = useCountUp(value);
    const body = (
        <div className="flex items-start justify-between gap-3 p-5">
            <div className="min-w-0">
                <p className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</p>
                <p className="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    {emptyLabel ? (
                        <span className="text-base font-bold text-ink-muted">{emptyLabel}</span>
                    ) : (
                        <>
                            <span className="truncate text-3xl font-extrabold text-ink tabular-nums" aria-label={format(value)}>
                                {format(shown)}
                            </span>
                            <Change change={change} previous={previous} />
                        </>
                    )}
                </p>
                {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
            </div>
            {Icon && (
                <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${colors.icon}`}>
                    <Icon className="size-5" aria-hidden="true" />
                </span>
            )}
        </div>
    );

    return (
        <Card hover={Boolean(href)} delay={delay} className={`border-t-2 ${colors.border}`}>
            {href ? (
                <Link href={href} className="block rounded-card focus-visible:outline-2 focus-visible:outline-primary">
                    {body}
                </Link>
            ) : (
                body
            )}
        </Card>
    );
}
