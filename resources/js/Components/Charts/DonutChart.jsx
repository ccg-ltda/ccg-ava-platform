import { useState } from 'react';

const TONES = {
    blue: { stroke: 'stroke-accent-blue', dot: 'bg-accent-blue' },
    violet: { stroke: 'stroke-accent-violet', dot: 'bg-accent-violet' },
    green: { stroke: 'stroke-accent-green', dot: 'bg-accent-green' },
    amber: { stroke: 'stroke-accent-amber', dot: 'bg-accent-amber' },
    red: { stroke: 'stroke-danger', dot: 'bg-danger' },
    neutral: { stroke: 'stroke-ink-muted', dot: 'bg-ink-muted' },
};

/**
 * Distribution as a donut with a legend, in plain SVG. `items`: [{ key, label, value, tone }]. Each legend row is a button
 * that shows or hides its segment (the percentages follow what is visible); hovering or focusing a row highlights its
 * segment and shows its figure in the middle. It draws only what it receives: with a total of zero the caller shows an empty state.
 */
export default function DonutChart({ items, totalLabel = 'Total', formatValue = (value) => value.toLocaleString('es'), ariaLabel }) {
    const [hidden, setHidden] = useState([]);
    const [focus, setFocus] = useState(null);

    const shown = items.filter((item) => !hidden.includes(item.key) && item.value > 0);
    const total = shown.reduce((sum, item) => sum + item.value, 0);
    const focused = items.find((item) => item.key === focus && !hidden.includes(item.key));
    const toggle = (key) => setHidden((now) => (now.includes(key) ? now.filter((item) => item !== key) : items.filter((item) => !now.includes(item.key)).length > 1 ? [...now, key] : now));

    let offset = 0;

    return (
        <div className="flex flex-col items-center gap-5">
            <div className="relative size-44 shrink-0">
                <svg viewBox="0 0 36 36" role="img" aria-label={ariaLabel} className="size-full -rotate-90">
                    <circle cx="18" cy="18" r="15.915" fill="none" className="stroke-canvas" strokeWidth="4" />
                    {total > 0 &&
                        shown.map((item) => {
                            const share = (item.value / total) * 100;
                            const segment = (
                                <circle
                                    key={item.key}
                                    cx="18"
                                    cy="18"
                                    r="15.915"
                                    fill="none"
                                    pathLength="100"
                                    strokeWidth={focus === item.key ? 5 : 4}
                                    strokeDasharray={`${Math.max(share - 0.6, 0.1)} ${100 - Math.max(share - 0.6, 0.1)}`}
                                    strokeDashoffset={-offset}
                                    className={`${TONES[item.tone ?? 'blue'].stroke} motion-safe:transition-[stroke-dasharray,stroke-width] motion-safe:duration-500`}
                                    onPointerEnter={() => setFocus(item.key)}
                                    onPointerLeave={() => setFocus(null)}
                                />
                            );
                            offset += share;

                            return segment;
                        })}
                </svg>
                <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                    <div>
                        <p className="text-2xl font-extrabold text-ink">{formatValue(focused ? focused.value : total)}</p>
                        <p className="max-w-24 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{focused ? focused.label : totalLabel}</p>
                    </div>
                </div>
            </div>

            <ul className="w-full min-w-0 flex-1 space-y-1.5">
                {items.map((item) => {
                    const on = !hidden.includes(item.key);

                    return (
                        <li key={item.key}>
                            <button
                                type="button"
                                aria-pressed={on}
                                onClick={() => toggle(item.key)}
                                onPointerEnter={() => setFocus(item.key)}
                                onPointerLeave={() => setFocus(null)}
                                onFocus={() => setFocus(item.key)}
                                onBlur={() => setFocus(null)}
                                className={`flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left text-sm transition-colors duration-150 hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary ${on ? 'text-ink' : 'text-ink-muted line-through'}`}
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <span className={`size-2.5 shrink-0 rounded-full ${TONES[item.tone ?? 'blue'].dot} ${on ? '' : 'opacity-40'}`} aria-hidden="true" />
                                    <span className="truncate">{item.label}</span>
                                </span>
                                <span className="shrink-0 font-semibold">
                                    {formatValue(item.value)}
                                    {on && total > 0 && item.value > 0 && <span className="ms-1.5 text-xs font-normal text-ink-muted">{Math.round((item.value / total) * 100)}%</span>}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
