import { useState } from 'react';
import useElementWidth from '@/Hooks/useElementWidth';
import ChartTooltip from './ChartTooltip';
import { MARGIN, niceScale, spreadIndexes } from './chartUtils';

/**
 * Time series drawn as an area line (`variant="line"`) or as bars (`variant="bars"`), in plain SVG: no chart library.
 * `points`: [{ key, label, short?, value }] in chronological order. Hover, touch and the arrow keys move a tooltip over
 * the points; `onSelect(point)` (optional) makes every point clickable, for drilling into a detail later.
 * It draws only what it receives: with no points the caller shows an empty state instead.
 */
export default function TimeChart({ points, variant = 'line', height = 240, formatValue = (value) => String(value), valueLabel = 'Total', onSelect, ariaLabel }) {
    const [ref, width] = useElementWidth();
    const [active, setActive] = useState(null);

    const inner = { w: width - MARGIN.left - MARGIN.right, h: height - MARGIN.top - MARGIN.bottom };
    const { top, ticks } = niceScale(Math.max(...points.map((point) => point.value)));
    const slot = inner.w / points.length;
    const x = (index) => MARGIN.left + (variant === 'bars' ? slot * index + slot / 2 : points.length === 1 ? inner.w / 2 : (inner.w * index) / (points.length - 1));
    const y = (value) => MARGIN.top + inner.h - (value / top) * inner.h;

    const nearest = (clientX) => {
        const box = ref.current.getBoundingClientRect();
        const position = clientX - box.left - MARGIN.left;
        const index = variant === 'bars' ? Math.floor(position / slot) : Math.round((position / inner.w) * (points.length - 1));

        return Math.min(Math.max(index, 0), points.length - 1);
    };

    const onKeyDown = (event) => {
        const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

        if (move) {
            event.preventDefault();
            setActive((current) => Math.min(Math.max((current ?? (move > 0 ? -1 : points.length)) + move, 0), points.length - 1));
        } else if (event.key === 'Escape') {
            setActive(null);
        } else if ((event.key === 'Enter' || event.key === ' ') && onSelect && active !== null) {
            event.preventDefault();
            onSelect(points[active]);
        }
    };

    const line = points.map((point, index) => `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(point.value).toFixed(1)}`).join(' ');
    const area = `${line} L${x(points.length - 1).toFixed(1)},${y(0)} L${x(0).toFixed(1)},${y(0)} Z`;
    const barWidth = Math.min(Math.max(slot * 0.6, 3), 36);
    const labels = spreadIndexes(points.length, Math.max(Math.floor(inner.w / 64), 2));
    const current = active === null ? null : points[active];

    return (
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
                onClick={(event) => onSelect && onSelect(points[nearest(event.clientX)])}
                className={`block overflow-visible rounded-md text-accent-blue outline-none focus-visible:ring-2 focus-visible:ring-primary/40 ${onSelect ? 'cursor-pointer' : ''}`}
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

                {variant === 'line' ? (
                    <>
                        <path d={area} className="fill-current opacity-10" />
                        <path d={line} fill="none" className="stroke-current" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
                        {points.length <= 40 &&
                            points.map((point, index) => <circle key={point.key} cx={x(index)} cy={y(point.value)} r={active === index ? 5 : 3} className="fill-card stroke-current" strokeWidth="2" />)}
                    </>
                ) : (
                    points.map((point, index) => {
                        const barHeight = Math.max(y(0) - y(point.value), point.value > 0 ? 2 : 0);

                        return <rect key={point.key} x={x(index) - barWidth / 2} y={y(0) - barHeight} width={barWidth} height={barHeight} rx="3" className={`fill-current ${active === index ? 'opacity-100' : 'opacity-70'}`} />;
                    })
                )}

                {current && <line x1={x(active)} x2={x(active)} y1={MARGIN.top} y2={y(0)} className="stroke-ink-muted/40" strokeWidth="1" />}
                {current && variant === 'line' && <circle cx={x(active)} cy={y(current.value)} r="5" className="fill-current" />}
            </svg>

            {current && <ChartTooltip x={x(active)} y={y(current.value)} width={width} title={current.label} value={`${formatValue(current.value)} ${valueLabel}`.trim()} />}
        </div>
    );
}
