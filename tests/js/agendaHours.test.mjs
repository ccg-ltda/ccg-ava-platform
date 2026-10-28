// Run with `npm run test:js`. The scale of the Agenda follows the opening hours of the sedes; nothing is invented for a sede without hours.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { agendaScale, clock, closedSegments, dayWindows, isoWeekday } from '../../resources/js/lib/agendaHours.js';

const week = (open, close, days = [1, 2, 3, 4, 5, 6, 7]) => Object.fromEntries(days.map((day) => [day, [open * 60, close * 60]]));
const hours = {
    1: week(8, 18),                       // Norte 8-18
    2: week(7, 17),                       // Sur 7-17
    3: week(9, 22, [1, 2, 3, 4, 5, 6]),   // Centro 9-22, closed on Sunday
    4: null,                              // a sede with no hours configured
};
// 2026-10-12 is a Monday, 2026-10-18 a Sunday.
const MONDAY = '2026-10-12';
const SUNDAY = '2026-10-18';

test('ISO weekdays', () => {
    assert.equal(isoWeekday(MONDAY), 1);
    assert.equal(isoWeekday(SUNDAY), 7);
});

test('one sede: the scale is its own hours', () => {
    assert.deepEqual(agendaScale(hours, 1, [MONDAY]), { from: 8, to: 18, configured: true });
    assert.deepEqual(agendaScale(hours, 2, [MONDAY]), { from: 7, to: 17, configured: true });
    assert.deepEqual(agendaScale(hours, 3, [MONDAY]), { from: 9, to: 22, configured: true });
});

test('all the sedes: from the earliest opening to the latest closing of the ones that are open', () => {
    const configured = { 1: hours[1], 2: hours[2], 3: hours[3] };

    assert.deepEqual(agendaScale(configured, null, [MONDAY]), { from: 7, to: 22, configured: true });
    // Sunday: Centro is closed, so the range is Norte and Sur only (7 to 18), not Centro's closing.
    assert.deepEqual(agendaScale(configured, null, [SUNDAY]), { from: 7, to: 18, configured: true });
    // A week that includes both days widens to the whole range.
    assert.deepEqual(agendaScale(configured, null, [MONDAY, SUNDAY]), { from: 7, to: 22, configured: true });
});

test('nothing is invented: with no hours, or every sede closed that day, the usual scale stays and it is never an invalid range', () => {
    assert.deepEqual(agendaScale({ 4: null }, null, [MONDAY]), { from: 8, to: 18, configured: false });
    assert.deepEqual(agendaScale({}, null, [MONDAY]), { from: 8, to: 18, configured: false });
    assert.deepEqual(agendaScale({ 3: week(9, 22, [1]) }, null, [SUNDAY]), { from: 8, to: 18, configured: false });
    assert.deepEqual(agendaScale(undefined, null, [MONDAY]), { from: 8, to: 18, configured: false });
    // Partial hours round outwards to whole hours: 07:30 - 17:15 shows 7 to 18.
    assert.deepEqual(agendaScale({ 1: { 1: [450, 1035] } }, 1, [MONDAY]), { from: 7, to: 18, configured: true });
});

test('a sede without hours adds no limit and is not drawn as closed', () => {
    assert.deepEqual(dayWindows(hours, null, MONDAY).unrestricted, true);
    assert.deepEqual(closedSegments(hours, null, MONDAY, 7, 22), []); // Sede 4 is not configured: nothing is shaded in the general view
    assert.deepEqual(closedSegments(hours, 4, MONDAY, 8, 18), []);
});

test('closed stretches of one sede and of the general view', () => {
    // Norte opens 8-18: in a grid 7-22 the stretches 7-8 and 18-22 are out of service.
    assert.deepEqual(closedSegments(hours, 1, MONDAY, 7, 22), [[420, 480], [1080, 1320]]);
    // General view of the three configured sedes (7-22 grid): somebody is open the whole time, nothing is shaded.
    const configured = { 1: hours[1], 2: hours[2], 3: hours[3] };
    assert.deepEqual(closedSegments(configured, null, MONDAY, 7, 22), []);
    // A closed day is closed all day.
    assert.deepEqual(closedSegments({ 3: hours[3] }, 3, SUNDAY, 9, 22), [[540, 1320]]);
    // Two sedes with a gap between them: 8-12 and 14-18 leaves 12-14 closed in the general view.
    assert.deepEqual(closedSegments({ 1: { 1: [480, 720] }, 2: { 1: [840, 1080] } }, null, MONDAY, 8, 18), [[720, 840]]);
});

test('clock', () => {
    assert.equal(clock(0), '00:00');
    assert.equal(clock(1075), '17:55');
});
