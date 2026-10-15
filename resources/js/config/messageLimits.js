// Length rules of a message body, shared by the editor and its tests. The server applies the same rules again (App\Campaigns\MessageTemplates):
// characters are Unicode code points (an emoji or an accented letter is ONE, a line break is ONE) and {{variables}} count as written.

const MARKER = /\{\{\s*([A-Za-z_]+)\s*\}\}/g;

/** A browser may send "\r\n"; it is one line break everywhere. */
export const normalizeBreaks = (text) => String(text ?? '').replace(/\r\n?/g, '\n');

export const countCharacters = (text) => Array.from(normalizeBreaks(text)).length;

/** 1024 -> "1.024", the same thousands separator the server writes in its messages. */
export const formatCount = (number) => String(number).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

/** How many characters the text has beyond `max` (0 when it fits). */
export const excess = (text, max) => Math.max(0, countCharacters(text) - max);

export const overflowMessage = (text, max) =>
    `El mensaje tiene ${formatCount(countCharacters(text))} caracteres y el máximo del cuerpo de una plantilla de WhatsApp es ${formatCount(max)}: sobran ${formatCount(excess(text, max))}. Acórtalo; Ava no lo recorta por ti.`;

/**
 * What typing, pasting or inserting does to a field with a limit. A change that would make the text longer than `max` is NOT applied (nothing is cut
 * or dropped silently: the person is told by how much it would exceed). Shortening a text that is already over the limit is always allowed.
 */
export function applyChange(previous, next, max) {
    const grows = countCharacters(next) > countCharacters(previous);

    if (grows && countCharacters(next) > max) {
        return { value: previous, rejected: `No se añadió: el mensaje superaría ${formatCount(max)} caracteres por ${formatCount(excess(next, max))}.` };
    }

    return { value: next, rejected: null };
}

/** Double braces that are not a variable of the shape {{nombre}}: stray "{{" or "}}", an empty "{{ }}" or a numbered "{{1}}". */
export function hasMalformedMarkers(body) {
    const rest = String(body ?? '').replace(MARKER, '');

    return rest.includes('{{') || rest.includes('}}');
}
