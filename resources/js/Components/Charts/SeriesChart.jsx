import { useState } from 'react';
import useElementWidth from '@/Hooks/useElementWidth';
import { MARGIN, niceScale, spreadIndexes } from './chartUtils';

/**
 * Several time series on one line chart, in plain SVG: no chart library. `points`: [{ key, label, short?, values: { [series]: n } }]
 * in chronological order; `series`: [{ key, label, className }] where `className` sets the color (a `text-*` class). The legend
 * is made of buttons that show or hide each series (the scale follows what is visible, and one series always stays). Hover, touch
 * and the arrow keys move a tooltip that lists every visible series at that point. It draws only what it receives.
 */
export default function SeriesChart({ points, series, height = 260, formatValue = (value) => value.toLocaleString('es'), ariaLabel }) {
    const [ref, width] = useElementWidth();
    const [hidden, setHidden] = useState([]);
    const [active, setActive] = useState(null);

    const visible = series.filter((item) => !hidden.includes(item.key));
    const inner = { w: width - MARGIN.left - MARGIN.right, h: height - MARGIN.top - MARGIN.bottom };
    const { top, ticks } = niceScale(Math.max(0, ...points.flatMap((point) => visible.map((item) => point.values[item.key] ?? 0))));
    const x = (index) => MARGIN.left + (points.length === 1 ? inner.w / 2 : (inner.w * index) / (points.length - 1));
    const y = (value) => MARGIN.top + inner.h - (value / top) * inner.h;
    const labels = spreadIndexes(points.length, Math.max(Math.floor(inner.w / 64), 2));
    const current = active === null ? null : points[active];

    const toggle = (key) => setHidden((now) => (now.includes(key) ? now.filter((item) => item !== key) : visible.length > 1 ? [...now, key] : now));

    const nearest = (clientX) => {
        const box = ref.current.getBoundingClientRect();

        return Math.min(Math.max(Math.round(((clientX - box.left - MARGIN.left) / inner.w) * (points.length - 1)), 0), points.length - 1);
    };

    const onKeyDown = (event) => {
        const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (move) {
            event.preventDefault();
            setActive((now) => Math.min(Math.max((now ?? (move > 0 ? -1 : points.length)) + move, 0), points.length - 1));
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    const line = (key) => points.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(point.values[key] ?? 0).toFixed(1)}`).join(' ');
    const tooltipLeft = current ? Math.min(Math.max(x(active), 90), Math.max(width - 90, 90)) : 0;

    return (
        <div>
            <div role="group" aria-label="Series visibles" className="mb-3 flex flex-wrap gap-2">
                {series.map((item) => {
                    const on = !hidden.includes(item.key);

                    return (
                        <button
                            key={item.key}
                            type="button"
                            aria-pressed={on}
                            onClick={() => toggle(item.key)}
                            className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary ${
                                on ? 'border-line bg-card text-ink' : 'border-dashed border-line bg-canvas text-ink-muted line-through'
                            }`}
                        >
                            <span className={`size-2.5 rounded-full bg-current ${item.className}`} aria-hidden="true" />
                            {item.label}
                        </button>
                    );
                })}
            </div>

            <div ref={ref} className="relative" style={{ height }}>
                <svg
                    width={width}
                    height={height}
                    role="img"
                    aria-label={ariaLabel}
                    tabIndex={0}
                    onKeyDown={onKeyDown}
                    onBlur={() => setActive(null)}
                    onPointerMove={(event) => setActive(nearest(event.clientX))}
                    onPointerLeave={() => setActive(null)}
                    className="block overflow-visible rounded-md outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                >
                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line x1={MARGIN.left} x2={width - MARGIN.right} y1={y(tick)} y2={y(tick)} className="stroke-line" strokeWidth="1" strokeDasharray={tick === 0 ? undefined : '3 4'} />
                            <text x={MARGIN.left - 8} y={y(tick)} textAnchor="end" dominantBaseline="middle" className="fill-ink-muted text-[11px]">
                                {formatValue(tick)}
                            </text>
                        </g>
                    ))}

                    {labels.map((index) => (
                        <text key={points[index].key} x={x(index)} y={height - 8} textAnchor={index === 0 ? 'start' : index === points.length - 1 ? 'end' : 'middle'} className="fill-ink-muted text-[11px]">
                            {points[index].short ?? points[index].label}
                        </text>
                    ))}

                    {current && <line x1={x(active)} x2={x(active)} y1={MARGIN.top} y2={y(0)} className="stroke-ink-muted/40" strokeWidth="1" />}

                    {visible.map((item) => (
                        <g key={item.key} className={item.className}>
                            <path d={line(item.key)} pathLength="1" strokeDasharray="1" fill="none" className="stroke-current motion-safe:animate-chart-draw" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
                            {points.length <= 40 && points.map((point, index) => <circle key={point.key} cx={x(index)} cy={y(point.values[item.key] ?? 0)} r={active === index ? 5 : 2.5} className="fill-card stroke-current" strokeWidth="2" />)}
                        </g>
                    ))}
                </svg>

                {current && (
                    <div role="status" className="pointer-events-none absolute z-10 w-max max-w-56 -translate-x-1/2 rounded-lg border border-line bg-card px-3 py-2 text-xs shadow-card-hover" style={{ left: tooltipLeft, top: 0 }}>
                        <p className="font-semibold text-ink">{current.label}</p>
                        <ul className="mt-1 space-y-0.5">
                            {visible.map((item) => (
                                <li key={item.key} className="flex items-center justify-between gap-4 text-ink-muted">
                                    <span className="flex items-center gap-1.5">
                                        <span className={`size-2 rounded-full bg-current ${item.className}`} aria-hidden="true" />
                                        {item.label}
                                    </span>
                                    <span className="font-semibold text-ink">{formatValue(current.values[item.key] ?? 0)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
}
