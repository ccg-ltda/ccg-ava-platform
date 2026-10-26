import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import { levelOf, placeLines, STATES, stockSummary, units } from '@/lib/inventory';

/** The picture of a product as a square thumbnail, or a calm empty state when it has none. */
export function ProductThumb({ product, size = 'size-12', iconSize = 'size-5' }) {
    // A product that points to a picture the server can no longer read says so (it is not the same as «no picture»): the person can upload it again.
    const [broken, setBroken] = useState(false);

    return (
        <span className={`grid ${size} shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-canvas`}>
            {product.imageUrl && !broken ? (
                <img src={product.imageUrl} alt="" loading="lazy" className="size-full object-cover" onError={() => setBroken(true)} />
            ) : (
                <ImageOff className={`${iconSize} ${broken ? 'text-danger' : 'text-ink-muted/60'}`} aria-label={broken ? 'La imagen guardada ya no está disponible: súbela de nuevo' : 'Sin imagen'} role="img">
                    {broken && <title>La imagen guardada ya no está disponible: súbela de nuevo</title>}
                </ImageOff>
            )}
        </span>
    );
}

/**
 * The stock of a product for the consulted place. One place: its quantity with the level («Suficiente», «Stock bajo», «Agotado»); with control on and a zero
 * quantity «Sin existencias»; with control on and no stock row «Sin configurar»; without control «No controlado». Every place: one line per place, never one
 * summed number. A deactivated product is another condition and has its own badge elsewhere.
 */
export default function StockInfo({ stock, locations = [], compact = false }) {
    const summary = stockSummary(stock);
    const size = compact ? 'text-xs' : 'text-sm';

    if (summary.kind === 'places') {
        return (
            <ul className="min-w-44 space-y-1" aria-label="Existencias por sede">
                {placeLines(stock, locations).map((line) => (
                    <li key={line.key} className="flex items-baseline justify-between gap-3 text-xs">
                        <span className="min-w-0 truncate text-ink-muted" title={line.name}>
                            {line.name}
                        </span>
                        {line.row ? (
                            <span className="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                {levelOf(line.row.level) && line.row.level !== 'ok' && line.row.quantity > 0 && <Badge tone={levelOf(line.row.level).tone}>{levelOf(line.row.level).label}</Badge>}
                                <span className="font-semibold text-ink tabular-nums">{line.row.quantity <= 0 ? STATES.empty : units(line.row.quantity)}</span>
                            </span>
                        ) : (
                            <span className="shrink-0 text-ink-muted">{STATES.unconfigured}</span>
                        )}
                    </li>
                ))}
            </ul>
        );
    }

    if (summary.quantity === null) {
        return (
            <span className="inline-flex flex-col">
                <span className={`${size} ${summary.kind === 'untracked' ? 'text-ink-muted' : 'font-semibold text-ink'}`}>{summary.text}</span>
                {summary.hint && <span className="text-xs text-ink-muted">{summary.hint}</span>}
            </span>
        );
    }

    const level = levelOf(stock.level);

    return (
        <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="text-sm font-semibold text-ink tabular-nums">{summary.empty ? STATES.empty : units(summary.quantity)}</span>
            {level && <Badge tone={level.tone}>{level.label}</Badge>}
            <span className="basis-full text-xs text-ink-muted">{summary.text}</span>
        </span>
    );
}
