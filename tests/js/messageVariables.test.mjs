// Run with `npm run test:js`. Covers the helpers shared by the booking-message editor and its preview.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EXAMPLE_VALUES, insertVariable, marker, move, renderText, templateParameters, variablesIn } from '../../resources/js/lib/messageVariables.js';

test('a variable is inserted at the cursor, replacing the selection, with the exact marker the server reads', () => {
    assert.equal(marker('nombre'), '{{nombre}}');
    assert.deepEqual(insertVariable('Hola , gracias', 'nombre', 5, 5), { text: 'Hola {{nombre}}, gracias', caret: 5 + '{{nombre}}'.length });
    assert.deepEqual(insertVariable('Hola XXX!', 'fecha', 5, 8), { text: 'Hola {{fecha}}!', caret: 5 + '{{fecha}}'.length });
});

test('without a known cursor the variable goes to the end, separated by a space', () => {
    assert.equal(insertVariable('Tu cita es el', 'fecha').text, 'Tu cita es el {{fecha}}');
    assert.equal(insertVariable('', 'hora').text, '{{hora}}');
    assert.equal(insertVariable('Hola ', 'nombre').text, 'Hola {{nombre}}');
});

test('the preview renders like the server: unknown markers are empty, spaces collapse, the ends are trimmed', () => {
    assert.equal(renderText('Hola {{nombre}}, tu cita de {{servicio}}.'), 'Hola Laura, tu cita de Corte de cabello.');
    assert.equal(renderText('Hola {{ nombre }} {{desconocida}} fin', EXAMPLE_VALUES), 'Hola Laura fin');
    assert.equal(renderText('  {{negocio}}  ', { negocio: 'Mi negocio' }), 'Mi negocio');
});

test('the variables a text uses are listed once, in order of appearance', () => {
    assert.deepEqual(variablesIn('{{hora}} {{nombre}} {{hora}}'), ['hora', 'nombre']);
    assert.deepEqual(variablesIn(''), []);
});

test('the template parameters keep the declared order and use a dash for an empty value, like the server', () => {
    assert.deepEqual(templateParameters(['nombre', 'hora', 'sede'], { nombre: 'Ana', hora: '', sede: 'Centro' }), [
        { position: 1, variable: 'nombre', value: 'Ana' },
        { position: 2, variable: 'hora', value: '-' },
        { position: 3, variable: 'sede', value: 'Centro' },
    ]);
});

test('moving a variable changes the order by one place and never leaves the list', () => {
    assert.deepEqual(move(['a', 'b', 'c'], 1, -1), ['b', 'a', 'c']);
    assert.deepEqual(move(['a', 'b', 'c'], 1, 1), ['a', 'c', 'b']);
    assert.deepEqual(move(['a', 'b'], 0, -1), ['a', 'b']);
    assert.deepEqual(move(['a', 'b'], 1, 1), ['a', 'b']);
});
