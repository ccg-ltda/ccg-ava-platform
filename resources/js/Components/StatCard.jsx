import Card from './Card';

const TONES = {
    blue: { border: 'border-t-primary', icon: 'bg-primary-soft text-primary' },
    violet: { border: 'border-t-accent-violet', icon: 'bg-accent-violet/10 text-accent-violet' },
    green: { border: 'border-t-accent-green', icon: 'bg-accent-green/10 text-accent-green' },
    amber: { border: 'border-t-accent-amber', icon: 'bg-accent-amber/10 text-accent-amber' },
    red: { border: 'border-t-danger', icon: 'bg-danger/10 text-danger' },
};

/** Metric card with a thin colored top border. Prefer blue; use the others sparingly. */
export default function StatCard({ label, value, hint, icon: Icon, tone = 'blue', delay = 0 }) {
    const colors = TONES[tone];

    return (
        <Card hover delay={delay} className={`border-t-2 p-5 ${colors.border}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">{label}</p>
                    <p className="mt-2 truncate text-3xl font-extrabold text-ink">{value}</p>
                    {hint && <p className="mt-1 text-xs text-ink-muted">{hint}</p>}
                </div>
                {Icon && (
                    <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${colors.icon}`}>
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                )}
            </div>
        </Card>
    );
}
