// Run with `npm run test:js`. The text of the notification of new conversations and messages: it says THAT something arrived, never what it says.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { alreadyOpen, describeEvents, nextDelay } from '../../resources/js/lib/conversationAlerts.js';

const event = (extra = {}) => ({ conversationId: 7, kind: 'message', channel: 'whatsapp', support: false, count: 1, ...extra });

test('nothing to say when nothing arrived', () => {
    assert.equal(describeEvents([]), null);
    assert.equal(describeEvents(undefined), null);
});

test('a new conversation, a new message and a support request each have their own plain text', () => {
    assert.deepEqual(describeEvents([event({ kind: 'conversation' })]), { title: 'Nueva conversación recibida', detail: 'WhatsApp', conversationId: 7 });
    assert.deepEqual(describeEvents([event()]), { title: 'Nuevo mensaje recibido', detail: 'WhatsApp', conversationId: 7 });
    assert.equal(describeEvents([event({ kind: 'conversation', support: true })]).title, 'Nueva solicitud de soporte');
    assert.equal(describeEvents([event({ count: 3 })]).title, '3 mensajes nuevos');
});

test('several conversations in one poll become one alert that opens the inbox, not a conversation', () => {
    const alert = describeEvents([event({ kind: 'conversation' }), event({ conversationId: 8, count: 2 })]);

    assert.equal(alert.conversationId, null);
    assert.match(alert.title, /1 conversaciones nuevas y 2 mensajes/);
});

test('whatever the server sends, the alert only contains its fixed texts and the channel name', () => {
    const alert = describeEvents([{ conversationId: 7, kind: 'message', channel: 'whatsapp', support: false, count: 1, body: 'mi clave es 1234', contactName: 'Ana', contactId: '+573001112233' }]);

    assert.equal(JSON.stringify(alert).includes('1234'), false);
    assert.equal(JSON.stringify(alert).includes('Ana'), false);
    assert.equal(JSON.stringify(alert).includes('573'), false);
});

test('an alert for the conversation the person is already looking at is skipped, only while the tab is visible', () => {
    const alert = { conversationId: 7 };

    assert.equal(alreadyOpen(alert, '?c=7', true), true);
    assert.equal(alreadyOpen(alert, '?c=7', false), false);
    assert.equal(alreadyOpen(alert, '?c=8', true), false);
    assert.equal(alreadyOpen({ conversationId: null }, '?c=7', true), false);
});

test('after failures the next question waits longer, never shorter, and is capped', () => {
    assert.equal(nextDelay(15, 0, false), 15000);
    assert.equal(nextDelay(15, 0, true), 30000);
    assert.ok(nextDelay(15, 2, false) > nextDelay(15, 1, false));
    assert.equal(nextDelay(15, 99, true), 5 * 60 * 1000);
    assert.equal(nextDelay(1, 0, false), 5000); // never faster than every 5 seconds
});
