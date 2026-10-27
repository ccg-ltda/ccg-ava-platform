// Pure rules of the notification of new conversations and messages (tested with `npm run test:js`). The server only says WHAT happened (ids and kinds); the text shown
// here never contains what a contact wrote, their name or number.

const CHANNELS = { whatsapp: 'WhatsApp', web: 'Web', instagram: 'Instagram', messenger: 'Messenger', telegram: 'Telegram' };

/**
 * The one alert for the events of a poll, or null when there are none.
 * @param {Array<{conversationId: number, kind: 'conversation'|'message', channel: string, support: boolean, count: number}>} events
 * @returns {{ title: string, detail: string|null, conversationId: number|null } | null}
 */
export function describeEvents(events) {
    if (!events?.length) return null;

    const fresh = events.filter((event) => event.kind === 'conversation');
    const messages = events.reduce((sum, event) => sum + event.count, 0);
    const first = events[0];
    const single = events.length === 1;
    const channel = first.support ? 'Soporte' : (CHANNELS[first.channel] ?? null);

    if (single && first.kind === 'conversation') {
        return { title: first.support ? 'Nueva solicitud de soporte' : 'Nueva conversación recibida', detail: first.support ? null : channel, conversationId: first.conversationId };
    }

    if (single) {
        return { title: first.count > 1 ? `${first.count} mensajes nuevos` : 'Nuevo mensaje recibido', detail: channel, conversationId: first.conversationId };
    }

    return {
        title: fresh.length > 0 ? `${fresh.length} conversaciones nuevas y ${messages - fresh.length} mensajes` : `${messages} mensajes nuevos`,
        detail: `en ${events.length} conversaciones`,
        conversationId: null,
    };
}

/** Whether an alert is redundant: the person is looking at that very conversation in a visible tab. */
export function alreadyOpen(alert, search, visible) {
    if (!alert?.conversationId || !visible) return false;

    return new URLSearchParams(search).get('c') === String(alert.conversationId);
}

/** How long to wait before asking again: the normal pace, then slower after failures (never faster), so a broken connection is not hammered or shouted about. */
export function nextDelay(seconds, failures, hidden) {
    const base = Math.max(5, seconds) * 1000 * (hidden ? 2 : 1);

    return Math.min(base * 2 ** Math.min(failures, 4), 5 * 60 * 1000);
}
