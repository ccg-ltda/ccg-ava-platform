/**
 * How the inventory reads on screen. The server decides every figure and level (`App\Orders\CatalogInventory`); these are only the words and tones, and the rule
 * that a total is never presented as if it were available everywhere. Pure functions, testable without a browser.
 */

/** Level of a stock as the server sends it: `ok`, `low` (at or below the minimum), `out` (nothing on hand). */
export const LEVELS = {
    ok: { label: 'Suficiente', tone: 'green' },
    low: { label: 'Stock bajo', tone: 'amber' },
    out: { label: 'Agotado', tone: 'red' },
};

/** The look of a level; `null` (a product without stock control) has none: it is not «out of stock». */
export const levelOf = (level) => LEVELS[level] ?? null;

/** What to say of a product in the list for the consulted place. `stock` is the `stock` object of the product (see CatalogInventory::stockView). */
export function stockSummary(stock) {
    if (!stock || !stock.tracked) return { kind: 'untracked', text: 'Sin control de inventario', quantity: null };

    if (stock.unavailableHere) return { kind: 'unavailable', text: 'Sin inventario en esta sede', quantity: null };

    if (stock.stocks.length === 0) return { kind: 'pending', text: 'Inventario pendiente de configurar', quantity: null };

    if (stock.total.scope === 'all') {
        const places = stock.total.places;

        return { kind: 'total', quantity: stock.total.quantity, text: `Total de ${places === 1 ? '1 sede' : `${places} sedes`}`, scope: 'all' };
    }

    return { kind: 'place', quantity: stock.selected?.quantity ?? stock.total.quantity, text: 'En esta sede', scope: 'one' };
}

/** "3 unidades" / "1 unidad". */
export const units = (quantity) => `${Number(quantity).toLocaleString('es')} ${Number(quantity) === 1 ? 'unidad' : 'unidades'}`;

/** The signed change of a movement: «+12», «−3». */
export const signed = (change) => (change > 0 ? `+${change.toLocaleString('es')}` : `−${Math.abs(change).toLocaleString('es')}`);

/** The tone of a movement type, so the history is scannable. */
export const MOVEMENT_TONES = { entry: 'green', adjustment: 'blue', sale: 'neutral', cancellation: 'amber' };

/** One empty row per location (or one general row) for the stock of a new product. */
export const emptyStocks = (locations) => (locations.length === 0 ? [{ location_id: '', name: 'Inventario general', quantity: '', min_quantity: '' }] : locations.map((location) => ({ location_id: location.id, name: location.name, quantity: '', min_quantity: '' })));

/** The rows to send: only the ones with a quantity written (a blank quantity means "no stock row in that location"). */
export const stocksPayload = (rows) => rows.filter((row) => row.quantity !== '' && row.quantity !== null).map((row) => ({ location_id: row.location_id === '' ? null : row.location_id, quantity: Number(row.quantity), min_quantity: row.min_quantity === '' ? 0 : Number(row.min_quantity) }));
