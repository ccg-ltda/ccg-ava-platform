// Run with `npm run test:js`. The time axis of the charts: even labels (a skipped label never looks like a missing day) and a left margin that fits the value labels.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { axisMargin, labelIndexes, MARGIN } from '../../resources/js/Components/Charts/chartUtils.js';

test('every point keeps its label when they all fit', () => {
    assert.deepEqual(labelIndexes(7, 10), { step: 1, indexes: [0, 1, 2, 3, 4, 5, 6] });
});

test('when they do not fit the labels are evenly spaced from the first point', () => {
    const { step, indexes } = labelIndexes(30, 10);

    assert.equal(step, 3);
    assert.deepEqual(indexes, [0, 3, 6, 9, 12, 15, 18, 21, 24, 27]);
    assert.ok(indexes.every((index, position) => position === 0 || index - indexes[position - 1] === step), 'the gap never changes along the axis');
});

test('the step is never below 1 and an empty or tiny limit still gives a label', () => {
    assert.equal(labelIndexes(5, 0).step, 5);
    assert.deepEqual(labelIndexes(1, 3).indexes, [0]);
});

test('the left margin grows with the widest value label and never shrinks below the default', () => {
    assert.equal(axisMargin(['0', '5']).left, MARGIN.left);
    assert.ok(axisMargin(['RD$ 0', 'RD$ 200 mil']).left > MARGIN.left);
    assert.ok(axisMargin(['RD$ 200,000.00']).left >= 14 * 6.6, 'a long label fits inside the margin');
    assert.equal(axisMargin(['1']).top, MARGIN.top, 'the other margins are untouched');
});
