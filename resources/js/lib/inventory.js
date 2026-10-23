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

/** The wording of a stock that is not a quantity. */
export const STATES = {
    untracked: 'No controlado',
    unconfigured: 'Sin configurar',
    empty: 'Sin existencias',
};

/**
 * What to say of a product in the list for the consulted place. `stock` is the `stock` object of the product (see CatalogInventory::stockView).
 * - no stock control: «No controlado» (it is not «out of stock»);
 * - control on and a quantity configured in the place (0 included: «Sin existencias»): the quantity of THAT place;
 * - control on and no stock row in the place: «Sin configurar» (nothing is invented as 0);
 * - every place: one line per place (see `placeLines`), never one summed number.
 */
export function stockSummary(stock) {
    if (!stock || !stock.tracked) return { kind: 'untracked', text: STATES.untracked, quantity: null };

    if (stock.unavailableHere) return { kind: 'unavailable', text: STATES.unconfigured, hint: 'Sin existencias registradas en esta sede', quantity: null };

    if (stock.stocks.length === 0) return { kind: 'pending', text: STATES.unconfigured, hint: 'Registra las existencias iniciales', quantity: null };

    if (stock.total.scope === 'all') return { kind: 'places', quantity: null, text: 'Por sede' };

    const quantity = stock.selected?.quantity ?? stock.total.quantity;

    return { kind: 'place', quantity, text: 'En esta sede', empty: quantity <= 0 };
}

/**
 * One line per place for the «every place» view: each location (or the general inventory) with its own quantity and level, «Sin configurar» for the ones with
 * control on but no stock row. `locations` are the active locations of the Workspace; a stock of an inactive location is kept with its name.
 */
export function placeLines(stock, locations) {
    const byLocation = new Map(stock.stocks.map((row) => [row.locationId, row]));
    const lines = locations.map((location) => ({ key: location.id, name: location.name, row: byLocation.get(location.id) ?? null }));

    stock.stocks.filter((row) => row.locationId === null || !locations.some((location) => location.id === row.locationId)).forEach((row) => lines.push({ key: row.locationId ?? 'general', name: row.location, row }));

    return lines;
}

/** «Sedes: Principal, Sede Norte»: only the places with inventory configured, from real data; '' when there is none. */
export function configuredPlaces(stock) {
    if (!stock?.tracked || stock.stocks.length === 0) return '';

    const names = stock.stocks.map((row) => row.location);

    return `${stock.stocks.every((row) => row.locationId === null) ? 'Inventario' : names.length === 1 ? 'Sede' : 'Sedes'}: ${names.join(', ')}`;
}

/**
 * The locations a product is offered in, out of the active ones of the Workspace. A historical product assigned to no location keeps every one (it is offered
 * everywhere until someone assigns it). The stock of a product is only listed and edited for these.
 */
export const offeredLocations = (product, locations) => (product?.locationIds?.length ? locations.filter((location) => product.locationIds.includes(location.id)) : locations);

/** "3 unidades" / "1 unidad". */
export const units = (quantity) => `${Number(quantity).toLocaleString('es')} ${Number(quantity) === 1 ? 'unidad' : 'unidades'}`;

/** The signed change of a movement: «+12», «−3». */
export const signed = (change) => (change > 0 ? `+${change.toLocaleString('es')}` : `−${Math.abs(change).toLocaleString('es')}`);

/** The tone of a movement type, so the history is scannable. */
export const MOVEMENT_TONES = { entry: 'green', adjustment: 'blue', sale: 'neutral', cancellation: 'amber' };

/** One empty row per location (or one general row) for the stock of a new product. */
export const emptyStocks = (locations) => (locations.length === 0 ? [{ location_id: '', name: 'Inventario general', quantity: '', min_quantity: '' }] : locations.map((location) => ({ location_id: location.id, name: location.name, quantity: '', min_quantity: '' })));

/** The rows to send: only the ones with a quantity written (a blank quantity means "no stock row in that location"). */
export const stocksPayload = (rows, offered = null) => rows.filter((row) => (offered === null || row.location_id === '' || offered.includes(row.location_id)) && row.quantity !== '' && row.quantity !== null).map((row) => ({ location_id: row.location_id === '' ? null : row.location_id, quantity: Number(row.quantity), min_quantity: row.min_quantity === '' ? 0 : Number(row.min_quantity) }));
