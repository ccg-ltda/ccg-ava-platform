const TONES = {
    blue: 'bg-primary-soft text-primary',
    violet: 'bg-accent-violet/10 text-accent-violet',
    green: 'bg-accent-green/10 text-accent-green',
    amber: 'bg-accent-amber/10 text-accent-amber',
    red: 'bg-danger/10 text-danger',
    neutral: 'bg-canvas text-ink-muted ring-1 ring-line',
};

export default function Badge({ tone = 'neutral', className = '', children }) {
    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider ${TONES[tone]} ${className}`}
        >
            {children}
        </span>
    );
}
