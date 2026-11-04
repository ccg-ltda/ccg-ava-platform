// Run with `npm run test:js`. Covers the helpers shared by the booking-message editor and its preview.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CUSTOMER_EXAMPLE_VALUES, EXAMPLE_VALUES, hasMalformedMarkers, insertVariable, marker, move, renderText, templateParameters, variablesIn } from '../../resources/js/lib/messageVariables.js';

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

test('a reminder preview fills the customer data with fictitious examples and leaves what is unknown empty', () => {
    assert.equal(renderText('Hola {{nombre_completo}}, te escribimos al {{telefono}} y a {{correo}}.', CUSTOMER_EXAMPLE_VALUES), 'Hola Laura Pérez, te escribimos al +57 300 123 4567 y a laura@ejemplo.com.');
    assert.equal(renderText('Hola {{nombre}} {{inventada}}', CUSTOMER_EXAMPLE_VALUES), 'Hola Laura');
    assert.equal(renderText('{{servicio}} con {{profesional}}', CUSTOMER_EXAMPLE_VALUES), 'Corte de cabello con Ana', 'a rule can preview the appointment data');
    assert.equal(renderText('', CUSTOMER_EXAMPLE_VALUES), '');
    assert.equal(renderText('Línea 1\nLínea 2 {{nombre}}', CUSTOMER_EXAMPLE_VALUES), 'Línea 1\nLínea 2 Laura', 'line breaks are kept');
});

test('several variables can be inserted one after another and what was written stays', () => {
    const first = insertVariable('Hola ', 'nombre', 5, 5);
    const second = insertVariable(`${first.text}, tu correo es `, 'correo');

    assert.equal(second.text, 'Hola {{nombre}}, tu correo es {{correo}}');
});

test('double braces that are not a variable are detected', () => {
    assert.equal(hasMalformedMarkers('Hola {{nombre}}'), false);
    assert.equal(hasMalformedMarkers('Hola {{nombre'), true);
    assert.equal(hasMalformedMarkers('Hola {{1}}'), true);
    assert.equal(hasMalformedMarkers('Hola }}'), true);
    assert.equal(hasMalformedMarkers('Hola {nombre}'), false);
    assert.equal(hasMalformedMarkers(''), false);
});
