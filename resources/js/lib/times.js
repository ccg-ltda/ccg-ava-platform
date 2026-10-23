// Helpers of the time field (pure, tested with `npm run test:js`). Times are `HH:MM` strings in the Workspace's wall clock; nothing here knows about
// time zones or business rules: the SERVER decides what a valid time is.

const TIME = /^([01]\d|2[0-3]):([0-5]\d)$/;

/** Whether the text is a valid `HH:MM`. */
export function isTime(value) {
    return TIME.test(value ?? '');
}

/** `{ hour, minute }` (numbers) of a valid `HH:MM`, or null. */
export function splitTime(value) {
    const match = TIME.exec(value ?? '');

    return match ? { hour: Number(match[1]), minute: Number(match[2]) } : null;
}

/** `HH:MM` for an hour and a minute. */
export function joinTime(hour, minute) {
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

/** The minutes offered for a step: 5 -> 0, 5, ... 55. A step that does not divide the hour falls back to 5. */
export function minuteOptions(step = 5) {
    const size = Number.isInteger(step) && step > 0 && 60 % step === 0 ? step : 5;

    return Array.from({ length: 60 / size }, (_, index) => index * size);
}

/** The minute of the list closest to `minute` (a stored 07 stays selectable when the step is 5: it is shown as it is, see `minutesFor`). */
export function minutesFor(minute, step = 5) {
    const options = minuteOptions(step);

    return minute === null || minute === undefined || options.includes(minute) ? options : [...options, minute].sort((a, b) => a - b);
}

/** Whether a time is outside the bounds a form declares (`HH:MM` strings compare as text). */
export function timeOutside(value, { min, max } = {}) {
    return Boolean(value && ((min && value < min) || (max && value > max)));
}
