/**
 * Ranking / distribution as horizontal bars. `items`: [{ key, label, value, hint? }] (any order: it sorts by value).
 * `renderLabel(item)` customizes the label (a Badge, for example); `onSelect(item)` makes each row a button.
 * Bars are relative to the largest value, or to the total with `share` (a distribution).
 */
export default function RankingList({ items, renderLabel, formatValue = (value) => String(value), share = false, onSelect }) {
    const sorted = [...items].sort((a, b) => b.value - a.value);
    const total = sorted.reduce((sum, item) => sum + item.value, 0);
    const scale = share ? total : (sorted[0]?.value ?? 0);

    return (
        <ul className="space-y-4">
            {sorted.map((item) => {
                const content = (
                    <>
                        <span className="flex items-center justify-between gap-3 text-sm">
                            <span className="min-w-0 truncate text-ink">{renderLabel ? renderLabel(item) : item.label}</span>
                            <span className="shrink-0 font-semibold text-ink">
                                {formatValue(item.value)}
                                {share && total > 0 && <span className="ms-1.5 text-xs font-normal text-ink-muted">{Math.round((item.value / total) * 100)}%</span>}
                            </span>
                        </span>
                        <span className="mt-2 block h-2 overflow-hidden rounded-full bg-canvas">
                            <span className="block h-full rounded-full bg-accent-blue transition-[width] duration-300" style={{ width: `${scale > 0 ? (item.value / scale) * 100 : 0}%` }} />
                        </span>
                        {item.hint && <span className="mt-1 block text-xs text-ink-muted">{item.hint}</span>}
                    </>
                );

                return (
                    <li key={item.key}>
                        {onSelect ? (
                            <button type="button" onClick={() => onSelect(item)} className="block w-full rounded-md text-left focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary">
                                {content}
                            </button>
                        ) : (
                            content
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
