/**
 * Helpers of the booking messages (confirmation, reminder...) that the editor and its preview share. Pure functions, testable without a browser.
 * The variables themselves come from the server (`config/bookings.php` notifications.variables): nothing here decides which exist.
 */

/** Same pattern the server uses (`BookingMessageComposer::PATTERN`). */
const PATTERN = /\{\{\s*([A-Za-z_]+)\s*\}\}/g;

/** Fictitious values to show how a message reads. They are examples, never data of the business. */
export const EXAMPLE_VALUES = {
    nombre: 'Laura',
    servicio: 'Corte de cabello',
    profesional: 'Ana Ríos',
    sede: 'Sede Centro',
    fecha: '21/10/2026',
    hora: '10:30 AM',
    negocio: 'Tu negocio',
};

/** What each variable stands for, in words a person understands. */
export const VARIABLE_LABELS = {
    nombre: 'Nombre del cliente',
    servicio: 'Servicio',
    profesional: 'Profesional',
    sede: 'Sede',
    fecha: 'Fecha de la cita',
    hora: 'Hora de la cita',
    negocio: 'Nombre del negocio',
};

/** The marker as it is written in a text. */
export const marker = (variable) => `{{${variable}}}`;

/** Replaces the markers like the server does: an unknown one becomes empty, repeated spaces collapse and the ends are trimmed. */
export function renderText(body, values = EXAMPLE_VALUES) {
    return String(body ?? '')
        .replace(PATTERN, (_, name) => values[name] ?? '')
        .replace(/[ \t]{2,}/g, ' ')
        .trim();
}

/** The variables a text uses, in order of appearance, without repeats. */
export function variablesIn(body) {
    return [...new Set([...String(body ?? '').matchAll(PATTERN)].map((match) => match[1]))];
}

/**
 * Inserts `variable` into `text` at the selection (replacing it when something is selected) and returns the new text and where the caret goes.
 * Without a selection (the field never had the focus) it is appended with a space when the text does not end in one.
 */
export function insertVariable(text, variable, start, end) {
    const value = String(text ?? '');
    const token = marker(variable);
    const from = Number.isInteger(start) ? start : value.length;
    const to = Number.isInteger(end) ? end : from;
    const before = value.slice(0, from);
    const spaced = Number.isInteger(start) || before === '' || /\s$/.test(before) ? token : ` ${token}`;

    return { text: `${before}${spaced}${value.slice(to)}`, caret: before.length + spaced.length };
}

/** The ordered parameters of a Meta template as the provider receives them: position (1-based), the variable and the example value. */
export function templateParameters(variables, values = EXAMPLE_VALUES) {
    return variables.map((variable, index) => ({ position: index + 1, variable, value: values[variable] || '-' }));
}

/** Moves the item at `index` by `delta` positions (a copy; out of range leaves the order as it was). */
export function move(items, index, delta) {
    const target = index + delta;

    if (target < 0 || target >= items.length) return items;

    const next = [...items];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}
