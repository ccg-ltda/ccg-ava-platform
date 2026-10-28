// The opening hours of the sedes as the Agenda uses them (pure functions, tested with `npm run test:js`). The server sends, for each sede the member may use,
// `null` (no hours configured: nothing is assumed for it) or `{ [weekday 1-7]: [opens, closes] }` in minutes since local midnight; a weekday with no entry is a closed day.
// This only DRAWS the scale and the closed stretches; the server decides what can be booked.

const DEFAULT_SCALE = { from: 8, to: 18 };

/** ISO weekday (1 = Monday … 7 = Sunday) of a `YYYY-MM-DD`. */
export function isoWeekday(date) {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();

    return weekday === 0 ? 7 : weekday;
}

/** The sedes a view is about: the chosen one, or every sede the member may use. */
const considered = (locationHours, placeId) => (placeId === null || placeId === undefined ? Object.keys(locationHours ?? {}) : [String(placeId)].filter((id) => id in (locationHours ?? {})));

/**
 * What the sedes of the view say about ONE day: `unrestricted` when any of them has no hours configured (it adds no limit, and nothing is assumed for it), and the
 * `windows` of the ones that are open that day. A configured sede with no entry that weekday is closed and contributes nothing.
 */
export function dayWindows(locationHours, placeId, date) {
    const weekday = isoWeekday(date);
    const windows = [];
    let unrestricted = false;
    let configured = 0;

    for (const id of considered(locationHours, placeId)) {
        const hours = locationHours[id];

        if (hours === null || hours === undefined) {
            unrestricted = true;
            continue;
        }

        configured += 1;

        if (hours[weekday]) windows.push(hours[weekday]);
    }

    return { windows, unrestricted, configured };
}

/**
 * The hours of the grid for the days shown: from the earliest opening to the latest closing among the sedes that are open on those days (one sede: its own hours; all
 * the sedes: the widest range, each sede keeping its own hours inside it). With nothing configured, or every sede closed on those days, the usual 8 to 18 scale stays: no
 * hours are invented for a sede, and no invalid range is produced.
 *
 * @returns {{ from: number, to: number, configured: boolean }} whole hours (`from` rounded down, `to` rounded up) and whether the sedes' hours decided it
 */
export function agendaScale(locationHours, placeId, days) {
    let open = Infinity;
    let close = -Infinity;

    for (const day of days) {
        for (const [start, end] of dayWindows(locationHours, placeId, day).windows) {
            open = Math.min(open, start);
            close = Math.max(close, end);
        }
    }

    if (!Number.isFinite(open) || close <= open) return { ...DEFAULT_SCALE, configured: false };

    return { from: Math.floor(open / 60), to: Math.min(24, Math.ceil(close / 60)), configured: true };
}

/**
 * The stretches of one day, inside the grid, in which NO sede of the view is open (to draw them as «fuera de servicio»), as `[from, to]` minutes. Nothing is
 * drawn when the view has a sede without hours (it is not closed: it is not configured) or when no sede has hours at all.
 */
export function closedSegments(locationHours, placeId, date, fromHour, toHour) {
    const { windows, unrestricted, configured } = dayWindows(locationHours, placeId, date);

    if (unrestricted || configured === 0) return [];

    const top = fromHour * 60;
    const bottom = toHour * 60;
    const open = windows.map(([a, b]) => [Math.max(a, top), Math.min(b, bottom)]).filter(([a, b]) => b > a).sort((x, y) => x[0] - y[0]);
    const closed = [];
    let cursor = top;

    for (const [a, b] of open) {
        if (a > cursor) closed.push([cursor, a]);
        cursor = Math.max(cursor, b);
    }

    if (cursor < bottom) closed.push([cursor, bottom]);

    return closed;
}

/** «HH:MM» of minutes since midnight. */
export const clock = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
