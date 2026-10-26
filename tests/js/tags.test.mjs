// Run with `npm run test:js`. Customer tags: the browser normalizes exactly as the server does (CustomerTagCatalog), and the form never adds an equivalent tag twice.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cleanTag, normalizeTag, withTag } from '../../resources/js/lib/tags.js';

test('capitalization, extra spaces and the decomposed accent are the same tag', () => {
    assert.equal(normalizeTag('  VIP '), 'vip');
    assert.equal(normalizeTag('Cliente   Frecuente'), 'cliente frecuente');
    assert.equal(normalizeTag('Café'), normalizeTag('Café'));
});

test('accents are kept: via and vía are different tags', () => {
    assert.notEqual(normalizeTag('via'), normalizeTag('vía'));
});

test('withTag adds a new tag once and ignores equivalent, empty or too long ones', () => {
    assert.deepEqual(withTag([], ' Mayorista '), ['mayorista']);
    assert.deepEqual(withTag(['mayorista'], 'MAYORISTA'), ['mayorista']);
    assert.deepEqual(withTag(['vip'], '   '), ['vip']);
    assert.deepEqual(withTag(['vip'], 'x'.repeat(41)), ['vip']);
    assert.equal(cleanTag(null), '');
});
