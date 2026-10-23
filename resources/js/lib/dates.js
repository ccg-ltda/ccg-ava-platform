// Small helpers for the date fields (pure, tested with `npm run test:js`). Dates are `YYYY-MM-DD` strings; nothing here knows about time zones or business rules:
// the SERVER decides what a valid date is, this only explains the bounds a form already declares (min / max) before the request is sent.

const ISO = /^\d{4}-\d{2}-\d{2}$/;

/** "12 oct 2026" for a `YYYY-MM-DD` (a calendar date, never moved by the browser's time zone). */
export function formatDate(date, locale = 'es') {
    if (!ISO.test(date ?? '')) return '';

    return new Date(`${date}T12:00:00Z`).toLocaleDateString(locale, { timeZone: 'UTC', day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '');
}

/** Whether the text is a real calendar date (2026-02-30 is not). */
export function isRealDate(value) {
    if (!ISO.test(value ?? '')) return false;

    const parsed = new Date(`${value}T12:00:00Z`);

    return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

/**
 * The message for a date outside the bounds its form declares, or null. An empty value is not a problem here (required-ness is the form's own rule).
 * `min` and `max` are optional `YYYY-MM-DD` strings; comparing them as text is correct for this format.
 */
export function dateProblem(value, { min, max } = {}) {
    if (!value) return null;
    if (!isRealDate(value)) return 'Escribe una fecha válida.';
    if (min && value < min) return `La fecha no puede ser anterior al ${formatDate(min)}.`;
    if (max && value > max) return `La fecha no puede ser posterior al ${formatDate(max)}.`;

    return null;
}

/** Whether a quick action («Hoy») makes sense: today must be inside the bounds. */
export function todayAllowed(today, { min, max } = {}) {
    return isRealDate(today) && !(min && today < min) && !(max && today > max);
}

const pad = (number) => String(number).padStart(2, '0');

/** `YYYY-MM-DD` for a year, a month (1-12) and a day. */
export function toIso(year, month, day) {
    return `${year}-${pad(month)}-${pad(day)}`;
}

/** `{ year, month }` (month 1-12) of a `YYYY-MM-DD`, or null. */
export function monthOf(date) {
    return isRealDate(date) ? { year: Number(date.slice(0, 4)), month: Number(date.slice(5, 7)) } : null;
}

/** The month `delta` months away from `{ year, month }`. */
export function shiftMonth({ year, month }, delta) {
    const index = year * 12 + (month - 1) + delta;

    return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}

/** "octubre 2026" for a month (never moved by the browser's time zone). */
export function monthTitle({ year, month }, locale = 'es') {
    return new Date(Date.UTC(year, month - 1, 15)).toLocaleDateString(locale, { timeZone: 'UTC', month: 'long', year: 'numeric' });
}

/**
 * The cells of a month as whole weeks starting on Monday: `{ date, inMonth }` per day. Always whole weeks (4 to 6 rows) so the panel keeps a stable shape.
 */
export function monthGrid({ year, month }) {
    const first = new Date(Date.UTC(year, month - 1, 1));
    const offset = (first.getUTCDay() + 6) % 7;
    const days = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const total = Math.ceil((offset + days) / 7) * 7;

    return Array.from({ length: total }, (_, index) => {
        const day = new Date(Date.UTC(year, month - 1, 1 - offset + index));

        return { date: toIso(day.getUTCFullYear(), day.getUTCMonth() + 1, day.getUTCDate()), inMonth: day.getUTCMonth() === month - 1 };
    });
}

/** Whether a day is outside the bounds a form declares. */
export function outsideBounds(date, { min, max } = {}) {
    return Boolean((min && date < min) || (max && date > max));
}
