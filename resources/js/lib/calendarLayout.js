// Layout of the Agenda calendar (day and week). Pure functions, so they are tested without a browser (`npm run test:js`).
// Times are MINUTES SINCE LOCAL MIDNIGHT in the Workspace's time zone (the server sends them), never browser-local dates.

export const HOUR_PX = 96; // 48 px per half hour: a 30-minute appointment still has room for its four data
const MIN_EVENT_MINUTES = 15; // only an appointment shorter than this is drawn taller than its length, so it can still be read and clicked

/** `YYYY-MM-DD` plus a number of days, without time zones getting in the way. */
export function shiftDay(date, days) {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + days));

    return next.toISOString().slice(0, 10);
}

/** The Monday of the week that contains a `YYYY-MM-DD` (weeks start on Monday). */
export function mondayOf(date) {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();

    return shiftDay(date, -((weekday + 6) % 7));
}

/** The first day of the month of a `YYYY-MM-DD`. */
export function monthStartOf(date) {
    return `${date.slice(0, 7)}-01`;
}

/** The first day of the month `delta` months away from the month of `date`. */
export function shiftMonthStart(date, delta) {
    const [year, month] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1 + delta, 1));

    return next.toISOString().slice(0, 10);
}

/** The `from` an agenda period starts at for a reference day: the day itself, its Monday-first week, or the first day of its month. */
export function periodStart(period, date) {
    return period === 'month' ? monthStartOf(date) : period === 'week' ? mondayOf(date) : date;
}

/** The marks of the time scale: one per half hour from `startHour` to `endHour` (`{ minute, label, hour }`, `hour` = on the hour). */
export function halfHourMarks(startHour, endHour) {
    return Array.from({ length: (endHour - startHour) * 2 }, (_, index) => {
        const minute = startHour * 60 + index * 30;

        return { minute, hour: index % 2 === 0, label: `${String(Math.floor(minute / 60)).padStart(2, '0')}:${index % 2 === 0 ? '00' : '30'}` };
    });
}

/** The days a grid shows: `count` consecutive days from `from` (1 for the day view, 7 for the week). */
export function gridDays(from, count) {
    return Array.from({ length: count }, (_, index) => shiftDay(from, index));
}

/**
 * The hours the grid covers: the working day (8 to 18 by default) widened to contain every appointment and its rest, so nothing is cut and an empty day is
 * still a readable grid.
 */
export function hourRange(events, { from = 8, to = 18 } = {}) {
    let start = from;
    let end = to;

    for (const event of events) {
        start = Math.min(start, Math.floor(event.startMinute / 60));
        end = Math.max(end, Math.ceil(Math.min(event.startMinute + event.durationMinutes + (event.restMinutes ?? 0), 24 * 60) / 60));
    }

    return { startHour: Math.max(0, start), endHour: Math.min(24, Math.max(end, start + 1)) };
}

/**
 * Places the appointments of ONE day side by side when they overlap (several professionals at the same time): each gets a `lane` and the number of `lanes` of
 * its group, so the width is shared instead of the columns multiplying with the number of professionals.
 */
export function layoutDay(events) {
    const sorted = [...events].sort((a, b) => a.startMinute - b.startMinute || b.durationMinutes - a.durationMinutes || a.id - b.id);
    const placed = [];
    let group = [];
    let groupEnd = -1;
    let groupId = 0;

    const close = () => {
        const lanes = group.reduce((max, item) => Math.max(max, item.lane + 1), 1);
        group.forEach((item) => placed.push({ ...item, lanes, group: groupId }));
        group = [];
        groupId += 1;
    };

    for (const event of sorted) {
        const end = event.startMinute + Math.max(event.durationMinutes, MIN_EVENT_MINUTES);

        if (group.length > 0 && event.startMinute >= groupEnd) {
            close();
            groupEnd = -1;
        }

        const taken = new Set(group.filter((item) => item.end > event.startMinute).map((item) => item.lane));
        let lane = 0;

        while (taken.has(lane)) lane += 1;

        group.push({ event, lane, end });
        groupEnd = Math.max(groupEnd, end);
    }

    close();

    return placed.map(({ event, lane, lanes, end, group: id }) => ({ ...event, lane, lanes, group: id, endMinute: end }));
}

/**
 * Keeps a crowded moment readable: when more than `maxLanes` appointments overlap, the first `maxLanes - 1` stay as they are and the rest collapse into ONE
 * «+N» entry for that moment (which lists them), instead of squeezing everything into slivers. A moment with up to `maxLanes` is untouched.
 */
export function collapseLanes(placed, maxLanes = 3) {
    const visible = [];
    const groups = new Map();

    for (const item of placed) {
        if (item.lanes <= maxLanes || item.lane < maxLanes - 1) {
            visible.push(item.lanes > maxLanes ? { ...item, lanes: maxLanes } : item);
            continue;
        }

        const entry = groups.get(item.group) ?? { id: item.group, lane: maxLanes - 1, lanes: maxLanes, startMinute: Infinity, endMinute: 0, durationMinutes: 0, events: [] };
        entry.startMinute = Math.min(entry.startMinute, item.startMinute);
        entry.endMinute = Math.max(entry.endMinute, item.endMinute);
        entry.events.push(item);
        groups.set(item.group, entry);
    }

    const overflow = [...groups.values()].map((entry) => ({ ...entry, durationMinutes: entry.endMinute - entry.startMinute }));

    return { visible, overflow };
}

/** CSS box of a placed appointment inside the hour range. */
export function eventBox(item, startHour) {
    const top = ((item.startMinute - startHour * 60) / 60) * HOUR_PX;
    const height = (Math.max(item.durationMinutes, MIN_EVENT_MINUTES) / 60) * HOUR_PX;

    return { top, height, left: `${(item.lane / item.lanes) * 100}%`, width: `${100 / item.lanes}%` };
}

/** A stable hue per professional (golden-angle steps keep neighbours apart), so the color means "who" in every view and theme. */
export function toneHue(id) {
    return Math.round(((Number(id) || 0) * 137.508) % 360);
}

/** "13 – 19 oct 2026", "13 oct 2026", "28 oct – 3 nov 2026" for the range the agenda shows. */
export function rangeLabel(from, lastDay, locale = 'es') {
    const parse = (date) => new Date(`${date}T12:00:00Z`);
    const part = (date, options) => parse(date).toLocaleDateString(locale, { timeZone: 'UTC', ...options });

    if (from === lastDay) return part(from, { day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '');

    const sameMonth = from.slice(0, 7) === lastDay.slice(0, 7);
    const first = part(from, sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' }).replace('.', '');
    const last = part(lastDay, { day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '');

    return `${first} – ${last}`;
}

/** The last day shown: the custom «Hasta» when there is one, otherwise `days` from the start. */
export function lastDayOf(filters) {
    return filters.to || shiftDay(filters.from, Math.max(filters.days, 1) - 1);
}

/** Groups appointments by their local day. */
export function byDay(appointments) {
    return appointments.reduce((groups, appointment) => ({ ...groups, [appointment.date]: [...(groups[appointment.date] ?? []), appointment] }), {});
}
