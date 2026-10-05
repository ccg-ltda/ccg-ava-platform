import Card from './Card';

const TONES = {
    blue: { border: 'border-t-primary', icon: 'bg-primary/15 text-accent-blue' },
    violet: { border: 'border-t-accent-violet', icon: 'bg-accent-violet/10 text-accent-violet' },
    green: { border: 'border-t-accent-green', icon: 'bg-accent-green/10 text-accent-green' },
    amber: { border: 'border-t-accent-amber', icon: 'bg-accent-amber/10 text-accent-amber' },
};

/**
 * One analytics metric. A metric with no real source has `value === null` and says "Sin datos todavía": it never shows
 * a placeholder number. `change` (optional, e.g. "+12%") and `onSelect` are for when the source exists.
 */
export default function MetricCard({ label, value, hint, icon: Icon, tone = 'blue', change, formatValue = (number) => number.toLocaleString('es'), onSelect, delay = 0 }) {
    const colors = TONES[tone];
    const hasValue = value !== null && value !== undefined;
    const Wrapper = onSelect ? 'button' : 'div';

    return (
        <Card hover={hasValue} delay={delay} className={`border-t-2 ${colors.border}`}>
            <Wrapper type={onSelect ? 'button' : undefined} onClick={onSelect} className={`flex w-full items-start justify-between gap-3 p-5 text-left ${onSelect ? 'focus-visible:outline-2 focus-visible:outline-primary' : ''}`}>
                <span className="min-w-0">
                    <span className="block text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</span>
                    {hasValue ? (
                        <span className="mt-2 flex items-baseline gap-2">
                            <span className="truncate text-3xl font-extrabold text-ink">{formatValue(value)}</span>
                            {change && <span className="text-xs font-semibold text-accent-green">{change}</span>}
                        </span>
                    ) : (
                        <span className="mt-2 block text-sm font-bold text-ink-muted">Sin datos todavía</span>
                    )}
                    {hint && <span className="mt-1 block text-xs text-ink-muted">{hint}</span>}
                </span>
                {Icon && (
                    <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${colors.icon}`}>
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                )}
            </Wrapper>
        </Card>
    );
}
