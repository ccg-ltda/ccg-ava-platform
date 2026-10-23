// Run with `npm run test:js`. The layout of the Agenda calendar: days, hours, side-by-side appointments and the range label.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { byDay, collapseLanes, eventBox, gridDays, halfHourMarks, HOUR_PX, hourRange, lastDayOf, layoutDay, mondayOf, periodStart, rangeLabel, shiftDay, shiftMonthStart, toneHue } from '../../resources/js/lib/calendarLayout.js';

const event = (id, startMinute, durationMinutes, restMinutes = 0, extra = {}) => ({ id, startMinute, durationMinutes, restMinutes, ...extra });

test('days are shifted and listed without time zones moving them', () => {
    assert.equal(shiftDay('2026-10-31', 1), '2026-11-01');
    assert.equal(shiftDay('2026-03-01', -1), '2026-02-28');
    assert.deepEqual(gridDays('2026-10-13', 7), ['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19']);
    assert.deepEqual(gridDays('2026-10-13', 1), ['2026-10-13']);
});

test('the hours cover the working day and widen to contain every appointment and its rest', () => {
    assert.deepEqual(hourRange([]), { startHour: 8, endHour: 18 });
    assert.deepEqual(hourRange([event(1, 6 * 60 + 30, 30)]), { startHour: 6, endHour: 18 });
    assert.deepEqual(hourRange([event(1, 17 * 60 + 30, 60, 30)]), { startHour: 8, endHour: 19 }); // ends 18:30, rest to 19:00
    assert.deepEqual(hourRange([event(1, 23 * 60, 120)]), { startHour: 8, endHour: 24 });          // never past midnight
});

test('overlapping appointments share the width and the others keep the whole width', () => {
    const placed = layoutDay([event(1, 600, 60), event(2, 615, 30), event(3, 700, 30), event(4, 640, 60)]);
    const by = Object.fromEntries(placed.map((item) => [item.id, item]));

    assert.equal(by[1].lanes, 3); // 10:00-11:00 overlaps 10:15 and 10:40
    assert.deepEqual([by[1].lane, by[2].lane, by[4].lane].sort(), [0, 1, 2]);
    assert.equal(by[3].lanes, 1); // 11:40 starts after the group ended
    assert.equal(by[3].lane, 0);
});

test('appointments that only touch do not share a lane, and many professionals never multiply the columns', () => {
    const touching = layoutDay([event(1, 600, 30), event(2, 630, 30)]);
    assert.deepEqual(touching.map((item) => [item.lane, item.lanes]), [[0, 1], [0, 1]]);

    const crowd = layoutDay(Array.from({ length: 6 }, (_, index) => event(index + 1, 600, 60)));
    assert.equal(crowd[0].lanes, 6); // six at once: one column split in six, not six columns
    assert.deepEqual(crowd.map((item) => item.lane).sort(), [0, 1, 2, 3, 4, 5]);
});

test('a placed appointment gets its box from its time, and a short one stays readable', () => {
    const [placed] = layoutDay([event(1, 9 * 60 + 30, 30)]);
    const box = eventBox(placed, 8);

    assert.equal(box.top, 1.5 * HOUR_PX);
    assert.equal(box.height, 0.5 * HOUR_PX);
    assert.equal(box.left, '0%');
    assert.equal(box.width, '100%');
    assert.ok(eventBox(layoutDay([event(2, 600, 5)])[0], 8).height >= (15 / 60) * HOUR_PX);
});

test('the color of a professional is stable and different from the next one', () => {
    assert.equal(toneHue(7), toneHue(7));
    assert.notEqual(toneHue(1), toneHue(2));
    assert.ok(toneHue(999) >= 0 && toneHue(999) < 360);
});

test('the visible range says what is shown', () => {
    assert.equal(lastDayOf({ from: '2026-10-13', days: 7, to: null }), '2026-10-19');
    assert.equal(lastDayOf({ from: '2026-10-13', days: 7, to: '2026-10-20' }), '2026-10-20');
    assert.equal(lastDayOf({ from: '2026-10-13', days: 1, to: null }), '2026-10-13');
    assert.match(rangeLabel('2026-10-13', '2026-10-13'), /13 oct/);
    assert.match(rangeLabel('2026-10-13', '2026-10-19'), /^13 – 19 oct/);
    assert.match(rangeLabel('2026-10-28', '2026-11-03'), /^28 oct – 3 nov/);
});

test('appointments are grouped by their local day', () => {
    const groups = byDay([{ id: 1, date: '2026-10-13' }, { id: 2, date: '2026-10-14' }, { id: 3, date: '2026-10-13' }]);

    assert.deepEqual(Object.keys(groups), ['2026-10-13', '2026-10-14']);
    assert.equal(groups['2026-10-13'].length, 2);
});

test('a crowded moment collapses into one «+N» and a normal one is untouched', () => {
    const crowd = layoutDay(Array.from({ length: 6 }, (_, index) => event(index + 1, 600, 60)));
    const { visible, overflow } = collapseLanes(crowd, 3);

    assert.equal(visible.length, 2);                       // the first two stay
    assert.ok(visible.every((item) => item.lanes === 3));  // in three columns: two appointments and the «+N»
    assert.equal(overflow.length, 1);
    assert.equal(overflow[0].events.length, 4);            // 6 - 2 hidden behind the entry
    assert.equal(overflow[0].lane, 2);
    assert.equal(overflow[0].startMinute, 600);

    const calm = collapseLanes(layoutDay([event(1, 600, 60), event(2, 610, 30), event(3, 620, 30)]), 3);
    assert.equal(calm.visible.length, 3);
    assert.equal(calm.overflow.length, 0);

    const apart = collapseLanes(layoutDay([...Array.from({ length: 5 }, (_, index) => event(index + 1, 600, 60)), event(9, 900, 30)]), 3);
    assert.equal(apart.overflow.length, 1);                // only the crowded moment collapses
    assert.ok(apart.visible.some((item) => item.id === 9 && item.lanes === 1));
});

test('periods start on the day, the Monday of the week or the first of the month', () => {
    assert.equal(periodStart('day', '2026-10-21'), '2026-10-21');
    assert.equal(periodStart('week', '2026-10-21'), '2026-10-19'); // Wednesday -> Monday
    assert.equal(periodStart('week', '2026-10-25'), '2026-10-19'); // Sunday belongs to the week that started on Monday
    assert.equal(periodStart('week', '2026-10-19'), '2026-10-19');
    assert.equal(periodStart('month', '2026-10-21'), '2026-10-01');
    assert.equal(shiftMonthStart('2026-10-21', 1), '2026-11-01');
    assert.equal(shiftMonthStart('2026-01-15', -1), '2025-12-01');
    assert.equal(mondayOf('2026-03-01'), '2026-02-23');
});

test('the time scale has a mark every 30 minutes', () => {
    const marks = halfHourMarks(9, 11);

    assert.deepEqual(marks.map((mark) => mark.label), ['09:00', '09:30', '10:00', '10:30']);
    assert.deepEqual(marks.map((mark) => mark.hour), [true, false, true, false]);
    assert.equal(marks[1].minute, 9 * 60 + 30);
});

test('an appointment is as tall as it lasts', () => {
    const [half] = layoutDay([event(1, 540, 30)]);
    const [hour] = layoutDay([event(2, 540, 60)]);

    assert.equal(eventBox(half, 9).height, 0.5 * HOUR_PX);
    assert.equal(eventBox(hour, 9).height, HOUR_PX);
});
