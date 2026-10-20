// Run with `npm run test:js`. Whole-row navigation must never steal a click from what already handles it.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isInteractiveTarget, shouldOpenRow } from '../../resources/js/lib/rowLink.js';

const target = (interactive) => ({ closest: () => (interactive ? {} : null) });
const click = (overrides = {}, interactive = false) => ({ button: 0, defaultPrevented: false, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, target: target(interactive), ...overrides });

test('a plain click on the row opens the detail', () => {
    assert.equal(shouldOpenRow(click()), true);
});

test('a click on a link, button, field or menu inside the row is left to that element', () => {
    assert.equal(isInteractiveTarget(target(true)), true);
    assert.equal(shouldOpenRow(click({}, true)), false);
});

test('modified clicks, other buttons, handled events and text selection never navigate', () => {
    for (const override of [{ metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }, { defaultPrevented: true }]) {
        assert.equal(shouldOpenRow(click(override)), false, JSON.stringify(override));
    }

    assert.equal(shouldOpenRow(click(), 'texto copiado'), false);
});

test('a target without closest (text node) is not interactive', () => {
    assert.equal(isInteractiveTarget({}), false);
    assert.equal(isInteractiveTarget(null), false);
});
