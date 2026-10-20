// Run with `npm run test:js`. The words and the rules of the inventory screens.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { emptyStocks, levelOf, signed, stockSummary, stocksPayload, units } from '../../resources/js/lib/inventory.js';

const tracked = (overrides = {}) => ({ tracked: true, stocks: [{ locationId: 1, quantity: 10 }], selected: null, total: { quantity: 10, places: 1, scope: 'all' }, level: 'ok', unavailableHere: false, ...overrides });

test('a product without stock control is not «out of stock»: it has no level and no quantity', () => {
    assert.equal(levelOf(null), null);
    assert.deepEqual(stockSummary({ tracked: false }), { kind: 'untracked', text: 'Sin control de inventario', quantity: null });
    assert.equal(stockSummary(null).kind, 'untracked');
});

test('the three levels have their own label and tone', () => {
    assert.deepEqual([levelOf('ok').tone, levelOf('low').tone, levelOf('out').tone], ['green', 'amber', 'red']);
    assert.deepEqual([levelOf('low').label, levelOf('out').label], ['Stock bajo', 'Agotado']);
});

test('a total over every location says how many locations it adds up and is never presented as available everywhere', () => {
    const all = stockSummary(tracked({ total: { quantity: 13, places: 2, scope: 'all' } }));
    assert.deepEqual([all.kind, all.quantity, all.text], ['total', 13, 'Total de 2 sedes']);
    assert.equal(stockSummary(tracked({ total: { quantity: 4, places: 1, scope: 'all' } })).text, 'Total de 1 sede');
});

test('a consulted location shows its own quantity, and a location without a stock row is unavailable there', () => {
    const one = stockSummary(tracked({ selected: { quantity: 3 }, total: { quantity: 3, places: 1, scope: 'one' } }));
    assert.deepEqual([one.kind, one.quantity, one.text], ['place', 3, 'En esta sede']);
    assert.equal(stockSummary(tracked({ unavailableHere: true, selected: null })).kind, 'unavailable');
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
