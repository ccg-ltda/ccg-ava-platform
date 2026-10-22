// Run with `npm run test:js`. The bounds of the date fields are explained before the request; the server still decides.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dateProblem, formatDate, isRealDate, todayAllowed } from '../../resources/js/lib/dates.js';

test('a calendar date is formatted without the browser time zone moving it', () => {
    assert.match(formatDate('2026-10-13'), /^13 oct 2026$/);
    assert.match(formatDate('2026-01-01'), /^1 ene 2026$/);
    assert.equal(formatDate(''), '');
    assert.equal(formatDate('13/10/2026'), '');
});

test('only real calendar dates are dates', () => {
    assert.equal(isRealDate('2026-02-28'), true);
    assert.equal(isRealDate('2028-02-29'), true);
    assert.equal(isRealDate('2026-02-30'), false);
    assert.equal(isRealDate('2026-13-01'), false);
    assert.equal(isRealDate('hoy'), false);
});

test('a date outside the declared bounds gets a clear message and an empty one none', () => {
    assert.equal(dateProblem('', { min: '2026-10-01' }), null);
    assert.equal(dateProblem('2026-10-05', { min: '2026-10-01', max: '2026-10-31' }), null);
    assert.equal(dateProblem('2026-10-01', { min: '2026-10-01', max: '2026-10-01' }), null, 'the bounds are inclusive');
    assert.match(dateProblem('2026-09-30', { min: '2026-10-01' }), /anterior al 1 oct 2026/);
    assert.match(dateProblem('2026-11-01', { max: '2026-10-31' }), /posterior al 31 oct 2026/);
    assert.equal(dateProblem('2026-02-30'), 'Escribe una fecha válida.');
});

test('the «Hoy» action is offered only when today is inside the bounds', () => {
    assert.equal(todayAllowed('2026-10-22', {}), true);
    assert.equal(todayAllowed('2026-10-22', { min: '2026-10-23' }), false);
    assert.equal(todayAllowed('2026-10-22', { max: '2026-10-21' }), false);
    assert.equal(todayAllowed('2026-10-22', { min: '2026-10-22', max: '2026-10-22' }), true);
    assert.equal(todayAllowed('', {}), false);
});
