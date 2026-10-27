// Run with `npm run test:js`. The line comparison used to review a proposed change to an instruction block.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { diffLines, hasChanges } from '../../resources/js/lib/diff.js';

const summary = (lines) => lines.map((line) => `${{ same: ' ', added: '+', removed: '-' }[line.type]}${line.text}`);

test('an appended line is shown as added and the rest stays the same', () => {
    assert.deepEqual(summary(diffLines('No des diagnósticos.', 'No des diagnósticos.\nNunca recetes.')), [' No des diagnósticos.', '+Nunca recetes.']);
});

test('a replaced line is a removal followed by an addition, in order', () => {
    assert.deepEqual(summary(diffLines('a\nb\nc', 'a\nB\nc')), [' a', '-b', '+B', ' c']);
});

test('removed lines are kept so the reviewer sees what disappears', () => {
    assert.deepEqual(summary(diffLines('a\nb', 'a')), [' a', '-b']);
});

test('empty texts are handled and identical texts have no changes', () => {
    assert.deepEqual(summary(diffLines('', 'nuevo')), ['+nuevo']);
    assert.deepEqual(summary(diffLines(null, '')), []);
    assert.equal(hasChanges(diffLines('igual\ntexto', 'igual\ntexto')), false);
    assert.equal(hasChanges(diffLines('uno', 'dos')), true);
});
