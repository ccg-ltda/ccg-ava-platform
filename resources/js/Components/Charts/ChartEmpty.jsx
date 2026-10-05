import { BarChart3 } from 'lucide-react';

/** Ghost of each chart type: dashed outlines only, with no values, so it can never be mistaken for real data. */
function Ghost({ type }) {
    const stroke = 'stroke-line';

    return (
        <svg viewBox="0 0 240 110" className="h-full w-full" aria-hidden="true" fill="none">
            {type === 'ranking' &&
                [14, 38, 62, 86].map((y, index) => <rect key={y} x="8" y={y} width={200 - index * 28} height="12" rx="6" className={stroke} strokeWidth="1.5" strokeDasharray="4 4" />)}
            {type === 'donut' && <circle cx="120" cy="55" r="38" className={stroke} strokeWidth="14" strokeDasharray="5 6" />}
            {type === 'bars' && (
                <>
                    <line x1="8" x2="232" y1="100" y2="100" className={stroke} strokeWidth="1.5" />
                    {[0, 1, 2, 3, 4, 5, 6].map((index) => (
                        <rect key={index} x={20 + index * 31} y="56" width="18" height="44" rx="3" className={stroke} strokeWidth="1.5" strokeDasharray="4 4" />
                    ))}
                </>
            )}
            {type === 'line' && (
                <>
                    {[20, 50, 80].map((y) => (
                        <line key={y} x1="8" x2="232" y1={y} y2={y} className={stroke} strokeWidth="1" strokeDasharray="3 5" />
                    ))}
                    <line x1="8" x2="232" y1="100" y2="100" className={stroke} strokeWidth="1.5" />
                    <line x1="8" x2="232" y1="62" y2="62" className={stroke} strokeWidth="2" strokeDasharray="2 7" strokeLinecap="round" />
                </>
            )}
        </svg>
    );
}

/**
 * Designed empty state of a chart: the outline of what will be drawn and a clear message. `type`: line | bars |
 * ranking | donut. Used both for "no data yet" and for "no results with these filters" (pass `title`/`description`).
 */
export default function ChartEmpty({ type = 'line', title = 'Sin datos todavía', description, height = 240, icon: Icon = BarChart3 }) {
    return (
        <div className="relative grid place-items-center overflow-hidden rounded-xl border border-dashed border-line bg-canvas/50" style={{ minHeight: height }}>
            <div className="absolute inset-0 p-6 opacity-70">
                <Ghost type={type} />
            </div>
            <div className="relative flex max-w-xs flex-col items-center rounded-xl bg-card/90 px-5 py-4 text-center shadow-card backdrop-blur-sm">
                <span className="mb-2 grid size-9 place-items-center rounded-full bg-accent-blue/10 text-accent-blue">
                    <Icon className="size-4" aria-hidden="true" />
                </span>
                <p className="text-sm font-semibold text-ink">{title}</p>
                {description && <p className="mt-1 text-xs text-ink-muted">{description}</p>}
            </div>
        </div>
    );
}
