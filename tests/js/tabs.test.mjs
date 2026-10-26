// Run with `npm run test:js`. Behavior of the shared Tabs: a panel that is absent (`{allowed && <Panel />}`) must not shift or break the others.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { loadJsx } from './support/loadJsx.mjs';

const render = async (tabs, panels, selectedIndex = 0) => {
    const { module, cleanup } = await loadJsx('resources/js/Components/Tabs.jsx');

    try {
        return renderToStaticMarkup(createElement(module.default, { tabs, selectedIndex, onChange: () => {} }, ...panels));
    } finally {
        cleanup();
    }
};

const four = ['General', 'Regional', 'Apariencia', 'Impuestos'].map((label) => ({ id: label.toLowerCase(), label }));
const panel = (text) => createElement('p', { key: text }, text);

test('a missing fifth panel (Configuraciones in a client Workspace) renders the four tabs and their panels without throwing', async () => {
    const html = await render(four, [panel('P-general'), panel('P-regional'), panel('P-apariencia'), panel('P-impuestos'), null]);

    for (const label of ['General', 'Regional', 'Apariencia', 'Impuestos']) assert.ok(html.includes(label), label);
    assert.ok(html.includes('P-general'), 'the selected panel is drawn');
});

test('false and null panels in the middle never misalign the rest', async () => {
    const tabs = [{ id: 'a', label: 'A' }, { id: 'c', label: 'C' }];
    const html = await render(tabs, [panel('P-a'), false, panel('P-c')], 1);

    assert.ok(html.includes('P-c'), 'the second tab shows the second REAL panel');
    assert.ok(!html.includes('P-a'));
});

test('the Configuraciones page passes the platform-only panel conditionally, so the tabs list and the panels list stay equal', async () => {
    const { readFileSync } = await import('node:fs');
    const page = readFileSync(new URL('../../resources/js/Pages/Settings/Index.jsx', import.meta.url), 'utf8');

    assert.match(page, /emailTemplate && <EmailTemplatePanel/);
    assert.match(page, /\.\.\.\(emailTemplate \? \[\{ id: 'correos'/);
});
