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
