import { ImageOff } from 'lucide-react';
import Badge from '@/Components/Badge';
import { levelOf, stockSummary, units } from '@/lib/inventory';

/** The picture of a product as a square thumbnail, or a calm empty state when it has none. */
export function ProductThumb({ product, size = 'size-12', iconSize = 'size-5' }) {
    return (
        <span className={`grid ${size} shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-canvas`}>
            {product.imageUrl ? <img src={product.imageUrl} alt="" loading="lazy" className="size-full object-cover" /> : <ImageOff className={`${iconSize} text-ink-muted/60`} aria-label="Sin imagen" role="img" />}
        </span>
    );
}

/**
 * The stock of a product for the consulted place: the quantity with its level («Suficiente», «Stock bajo», «Agotado»), or why there is none (no stock control,
 * not stocked in this location, still to configure). A total over every location says so; it is never shown as available everywhere. A deactivated product
 * is another condition and has its own badge elsewhere.
 */
export default function StockInfo({ stock, compact = false }) {
    const summary = stockSummary(stock);

    if (summary.quantity === null) {
        return <span className={`text-ink-muted ${compact ? 'text-xs' : 'text-sm'}`}>{summary.text}</span>;
    }

    const level = levelOf(stock.level);

    return (
        <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="text-sm font-semibold text-ink tabular-nums">{units(summary.quantity)}</span>
            {level && <Badge tone={level.tone}>{level.label}</Badge>}
            <span className="basis-full text-xs text-ink-muted">{summary.text}</span>
        </span>
    );
}
