// Run with `npm run test:js`. The words and the rules of the inventory screens.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { configuredPlaces, emptyStocks, levelOf, placeLines, signed, stockSummary, stocksPayload, units } from '../../resources/js/lib/inventory.js';

const tracked = (overrides = {}) => ({ tracked: true, stocks: [{ locationId: 1, quantity: 10 }], selected: null, total: { quantity: 10, places: 1, scope: 'all' }, level: 'ok', unavailableHere: false, ...overrides });

test('a product without stock control is not «out of stock»: it has no level and no quantity', () => {
    assert.equal(levelOf(null), null);
    assert.deepEqual(stockSummary({ tracked: false }), { kind: 'untracked', text: 'No controlado', quantity: null });
    assert.equal(stockSummary(null).kind, 'untracked');
});

test('the three levels have their own label and tone', () => {
    assert.deepEqual([levelOf('ok').tone, levelOf('low').tone, levelOf('out').tone], ['green', 'amber', 'red']);
    assert.deepEqual([levelOf('low').label, levelOf('out').label], ['Stock bajo', 'Agotado']);
});

test('every-place view never sums the places into one number: it lists each place with its own state', () => {
    const stock = tracked({ stocks: [{ locationId: 1, location: 'Principal', quantity: 5, min: 2, level: 'ok' }, { locationId: 2, location: 'Norte', quantity: 0, min: 0, level: 'out' }], total: { quantity: 5, places: 2, scope: 'all' } });
    assert.deepEqual([stockSummary(stock).kind, stockSummary(stock).quantity], ['places', null]);

    const lines = placeLines(stock, [{ id: 1, name: 'Principal' }, { id: 2, name: 'Norte' }, { id: 3, name: 'Sur' }]);
    assert.deepEqual(lines.map((line) => [line.name, line.row?.quantity ?? 'sin configurar']), [['Principal', 5], ['Norte', 0], ['Sur', 'sin configurar']]);
    assert.equal(configuredPlaces(stock), 'Sedes: Principal, Norte');
    assert.equal(configuredPlaces(tracked({ stocks: [{ locationId: 1, location: 'Principal' }] })), 'Sede: Principal');
    assert.equal(configuredPlaces(tracked({ stocks: [{ locationId: null, location: 'Inventario general' }] })), 'Inventario: Inventario general');
    assert.equal(configuredPlaces(tracked({ stocks: [] })), '');
    assert.equal(configuredPlaces({ tracked: false, stocks: [] }), '');
});

test('a consulted location shows its own quantity (zero is «Sin existencias», not pending); no row there is «Sin configurar»', () => {
    const one = stockSummary(tracked({ selected: { quantity: 3 }, total: { quantity: 3, places: 1, scope: 'one' } }));
    assert.deepEqual([one.kind, one.quantity, one.empty], ['place', 3, false]);
    assert.equal(stockSummary(tracked({ selected: { quantity: 0 }, total: { quantity: 0, places: 1, scope: 'one' } })).empty, true);
    const missing = stockSummary(tracked({ unavailableHere: true, selected: null }));
    assert.deepEqual([missing.kind, missing.text], ['unavailable', 'Sin configurar']);
    assert.equal(stockSummary(tracked({ stocks: [] })).kind, 'pending', 'control on but nothing configured yet');
});

test('quantities and movements read naturally', () => {
    assert.equal(units(1), '1 unidad');
    assert.equal(units(12), '12 unidades');
    assert.equal(signed(5), '+5');
    assert.equal(signed(-3), '−3');
});

test('the stock rows of a new product: one per location, only the ones with a quantity are sent, nothing is invented', () => {
    const rows = emptyStocks([{ id: 4, name: 'Centro' }, { id: 5, name: 'Norte' }]);
    assert.deepEqual(rows.map((row) => row.location_id), [4, 5]);
    assert.deepEqual(emptyStocks([]).map((row) => [row.location_id, row.name]), [['', 'Inventario general']]);

    rows[0].quantity = '8';
    rows[0].min_quantity = '2';
    assert.deepEqual(stocksPayload(rows), [{ location_id: 4, quantity: 8, min_quantity: 2 }]);
    assert.deepEqual(stocksPayload(emptyStocks([])), []);
    assert.deepEqual(stocksPayload([{ location_id: '', quantity: '0', min_quantity: '' }]), [{ location_id: null, quantity: 0, min_quantity: 0 }], 'zero is a real quantity');
});
