// Run with `npm run test:js`. The editor and the server (App\Campaigns\MessageTemplates) must count a message body the same way.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { applyChange, countCharacters, excess, formatCount, hasMalformedMarkers, overflowMessage } from '../../resources/js/config/messageLimits.js';

const source = (path) => readFileSync(fileURLToPath(new URL(`../../resources/js/${path}`, import.meta.url)), 'utf8');

test('characters are Unicode characters: an emoji or an accent is one, a line break is one', () => {
    assert.equal(countCharacters('😀'), 1);
    assert.equal('😀'.length, 2, 'the UTF-16 length that maxLength would use counts it twice');
    assert.equal(countCharacters('é'), 1);
    assert.equal(countCharacters('a\r\nb'), 3, 'CRLF is one line break');
    assert.equal(countCharacters('a\nb'), 3);
    assert.equal(countCharacters('👨‍👩‍👧'), 5, 'a family emoji is five code points: the server counts them the same way');
    assert.equal(countCharacters('Hola {{nombre}}'), 15, 'a variable counts as written');
});

test('the counter numbers read like the server messages', () => {
    assert.equal(formatCount(1024), '1.024');
    assert.equal(formatCount(245), '245');
    assert.equal(excess('a'.repeat(1030), 1024), 6);
    assert.equal(
        overflowMessage('a'.repeat(1025), 1024),
        'El mensaje tiene 1.025 caracteres y el máximo del cuerpo de una plantilla de WhatsApp es 1.024: sobran 1. Acórtalo; Ava no lo recorta por ti.',
    );
});

test('a change that would pass the limit is refused whole, never cut', () => {
    const full = 'a'.repeat(1024);

    assert.deepEqual(applyChange(full, `${full}b`, 1024), { value: full, rejected: 'No se añadió: el mensaje superaría 1.024 caracteres por 1.' });
    assert.equal(applyChange('a'.repeat(1000), 'a'.repeat(1000) + 'b'.repeat(30), 1024).value.length, 1000, 'a paste that does not fit is not partly applied');
    assert.equal(applyChange(full, `${full.slice(0, 1023)}`, 1024).rejected, null);
    assert.equal(applyChange('😀'.repeat(1023), `${'😀'.repeat(1023)}😀`, 1024).rejected, null, 'an emoji is one character, so 1024 of them fit');
    assert.notEqual(applyChange('😀'.repeat(1024), `${'😀'.repeat(1024)}😀`, 1024).rejected, null);
});

test('a text that is already over the limit can always be shortened', () => {
    const over = 'a'.repeat(1100);

    assert.equal(applyChange(over, over.slice(0, 1090), 1024).rejected, null);
    assert.equal(applyChange(over, over.slice(0, 1090), 1024).value.length, 1090);
});

test('malformed double braces are detected and valid variables are not', () => {
    assert.equal(hasMalformedMarkers('Hola {{nombre}} y {{ apellido }}'), false);
    assert.equal(hasMalformedMarkers('Hola {nombre}'), false);
    for (const body of ['Hola {{1}}', 'Hola {{nombre', 'Hola nombre}}', 'Hola {{}}', 'Hola {{ nombre completo }}']) assert.equal(hasMalformedMarkers(body), true, body);
});

test('the editor uses the shared rule and not the UTF-16 maxLength', () => {
    const textarea = source('Pages/Campaigns/Partials/LimitedTextarea.jsx');

    assert.ok(textarea.includes('applyChange'));
    assert.ok(!/maxLength=/.test(textarea), 'maxLength would count an emoji twice and cut pasted text silently');
    assert.ok(textarea.includes('{count} / {max}'), 'the counter is always visible');
    for (const file of ['Pages/Campaigns/Partials/MessageEditor.jsx', 'Pages/Campaigns/Index.jsx']) assert.ok(source(file).includes('LimitedTextarea'), file);
});

test('the professional form does not use the message limit', () => {
    const modal = source('Pages/Professionals/Partials/ProfessionalModal.jsx');

    assert.ok(!modal.includes('messageLimits') && !modal.includes('LimitedTextarea'));
});
