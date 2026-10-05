/** Floating label of a chart point. `x` is the pixel position inside the chart box, clamped so it never leaves it. */
export default function ChartTooltip({ x, y, width, title, value, hint }) {
    const left = Math.min(Math.max(x, 70), Math.max(width - 70, 70));

    return (
        <div
            role="status"
            className="pointer-events-none absolute z-10 w-max max-w-48 -translate-x-1/2 -translate-y-full rounded-lg border border-line bg-card px-3 py-2 text-xs shadow-card-hover"
            style={{ left, top: Math.max(y - 10, 0) }}
        >
            <p className="font-semibold text-ink">{value}</p>
            <p className="mt-0.5 text-ink-muted">{title}</p>
            {hint && <p className="mt-0.5 text-ink-muted">{hint}</p>}
        </div>
    );
}
