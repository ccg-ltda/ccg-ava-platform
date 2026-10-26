// Run with `npm run test:js`. The shared date, time and entity pickers: their pure rules, and that every module uses the ONE component instead of a native input
// or a copy of the picker. The behavior the person sees (open, pick, close) is the component's; the server still decides what is valid.
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { monthGrid, monthOf, monthTitle, outsideBounds, shiftMonth, toIso } from '../../resources/js/lib/dates.js';
import { toggleValue } from '../../resources/js/lib/pickers.js';
import { isTime, joinTime, minuteOptions, minutesFor, splitTime, timeOutside } from '../../resources/js/lib/times.js';

const root = new URL('../../resources/js/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const source = (file) => readFileSync(join(root, file), 'utf8');
const files = (dir) => readdirSync(join(root, dir)).flatMap((name) => {
    const path = join(dir, name);

    return statSync(join(root, path)).isDirectory() ? files(path) : [path];
});

test('the month grid is made of whole weeks that start on Monday', () => {
    const october = monthGrid({ year: 2026, month: 10 }); // 1 October 2026 is a Thursday

    assert.equal(october.length % 7, 0);
    assert.equal(october[0].date, '2026-09-28');
    assert.equal(october[0].inMonth, false);
    assert.equal(october[3].date, '2026-10-01');
    assert.equal(october[3].inMonth, true);
    assert.equal(october.filter((cell) => cell.inMonth).length, 31);
    assert.equal(october.at(-1).date, '2026-11-01');

    const february = monthGrid({ year: 2027, month: 2 }); // starts on a Monday and has 28 days: exactly four weeks
    assert.equal(february.length, 28);
    assert.equal(february[0].date, '2027-02-01');
});

test('months are shifted across years and titled in Spanish', () => {
    assert.deepEqual(shiftMonth({ year: 2026, month: 12 }, 1), { year: 2027, month: 1 });
    assert.deepEqual(shiftMonth({ year: 2026, month: 1 }, -1), { year: 2025, month: 12 });
    assert.deepEqual(shiftMonth({ year: 2026, month: 10 }, 0), { year: 2026, month: 10 });
    assert.match(monthTitle({ year: 2026, month: 10 }), /octubre.*2026/i);
    assert.deepEqual(monthOf('2026-10-21'), { year: 2026, month: 10 });
    assert.equal(monthOf(''), null);
    assert.equal(toIso(2026, 3, 9), '2026-03-09');
});

test('the days outside the bounds of the form are not selectable', () => {
    assert.equal(outsideBounds('2026-10-01', { min: '2026-10-02' }), true);
    assert.equal(outsideBounds('2026-10-02', { min: '2026-10-02' }), false);
    assert.equal(outsideBounds('2026-10-20', { max: '2026-10-19' }), true);
    assert.equal(outsideBounds('2026-10-20', {}), false);
});

test('times are HH:MM, split and joined without losing a stored value', () => {
    assert.equal(isTime('09:30'), true);
    assert.equal(isTime('24:00'), false);
    assert.equal(isTime('9:30'), false);
    assert.deepEqual(splitTime('17:05'), { hour: 17, minute: 5 });
    assert.equal(splitTime(''), null);
    assert.equal(joinTime(7, 5), '07:05');
    assert.equal(timeOutside('08:00', { min: '09:00' }), true);
    assert.equal(timeOutside('09:00', { min: '09:00', max: '17:00' }), false);
    assert.equal(timeOutside('', { min: '09:00' }), false, 'empty is the form\'s own rule');
});

test('the minutes offered follow the step and a stored minute outside it stays selectable', () => {
    assert.deepEqual(minuteOptions(15), [0, 15, 30, 45]);
    assert.equal(minuteOptions(5).length, 12);
    assert.equal(minuteOptions(7).length, 12, 'a step that does not divide the hour falls back to 5');
    assert.deepEqual(minutesFor(7, 15), [0, 7, 15, 30, 45], 'editing a 09:07 schedule does not lose the minute');
    assert.deepEqual(minutesFor(30, 15), [0, 15, 30, 45]);
});

test('choosing an entity twice removes it and never duplicates it', () => {
    assert.deepEqual(toggleValue([1, 2], 3), [1, 2, 3]);
    assert.deepEqual(toggleValue([1, 2, 3], 2), [1, 3]);
    assert.deepEqual(toggleValue([], 5), [5]);
});

test('the date field picks, closes and offers Hoy and Limpiar; no page uses a native date or time input any more', () => {
    const field = source('Components/DateField.jsx');
    assert.match(field, /PopoverButton/);
    assert.match(field, /close\(\);/, 'picking a day closes the calendar');
    assert.match(field, /Hoy/);
    assert.match(field, /Limpiar/);
    assert.match(field, /clearable/);
    assert.doesNotMatch(field, />\s*Guardar\s*</, 'no separate save button');

    for (const file of files('Pages')) {
        assert.doesNotMatch(source(file), /type="(date|time|datetime-local)"/, `${file} must use DateField / TimeField`);
    }
});

test('the time field is hours and minutes only and closes when the minute confirms it', () => {
    const field = source('Components/TimeField.jsx');
    assert.match(field, /aria-label="Hora"/);
    assert.match(field, /aria-label="Minutos"/);
    assert.match(field, /onPick\(joinTime/);
    assert.match(field, /close\(\)/);
    assert.doesNotMatch(field, /Date\(/, 'a time field knows no dates');
});

test('the schedules, the exceptions and the reminders use the shared time and date fields', () => {
    assert.match(source('Pages/Professionals/Partials/ScheduleEditor.jsx'), /<TimeField/);
    assert.match(source('Pages/Professionals/Partials/ResourcesAndExceptions.jsx'), /<TimeField/);
    assert.match(source('Pages/Professionals/Partials/ResourcesAndExceptions.jsx'), /<DateField/);
    assert.match(source('Pages/Catalog/Reminders.jsx'), /<TimeField/);
    assert.match(source('Pages/Catalog/Reminders.jsx'), /<DateField/);
    assert.match(source('Pages/Bookings/Partials/AgendaPanel.jsx'), /<DateField/);
    assert.match(source('Pages/Audit/Partials/AuditFilters.jsx'), /<DateField/);
});

test('every list of entities is chosen with the one EntityPicker (no checkbox cards, no copies)', () => {
    const picker = source('Components/EntityPicker.jsx');
    assert.match(picker, /Combobox/);
    assert.match(picker, /Quitar \$\{label\}/, 'each chosen option can be removed on its own');
    assert.match(picker, /aria-label="Seleccionadas"/);

    for (const file of [
        'Pages/Services/Index.jsx',
        'Pages/Locations/Index.jsx',
        'Pages/Professionals/Partials/ProfessionalModal.jsx',
        'Pages/Catalog/Partials/ProductModal.jsx',
        'Pages/Users/Partials/LocationScopeModal.jsx',
        'Pages/Catalog/Reminders.jsx',
    ]) {
        const text = source(file);
        assert.match(text, /<EntityPicker/, `${file} uses the shared picker`);
        assert.doesNotMatch(text, /has-checked:border-primary\/50/, `${file} has no checkbox cards left`);
    }
});

test('every icon a page or component draws is imported (a missing import blanks the whole screen at render time)', () => {
    for (const file of [...files('Pages'), ...files('Components')].filter((name) => name.endsWith('.jsx'))) {
        const text = source(file);
        const imported = new Set([...text.matchAll(/import\s*\{([^}]*)\}\s*from\s*'lucide-react'/g)].flatMap((match) => match[1].split(',').map((name) => name.trim().split(/\s+as\s+/).pop())));
        const defined = new Set([...text.matchAll(/(?:const|function|import)\s+([A-Z][A-Za-z0-9]*)/g)].map((match) => match[1]));

        for (const [, name] of text.matchAll(/\bicon=\{([A-Z][A-Za-z0-9]*)\}/g)) {
            assert.ok(imported.has(name) || defined.has(name), `${file} draws <${name}> without importing it`);
        }
    }
});
