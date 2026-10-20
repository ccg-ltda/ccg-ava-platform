// Run with `npm run test:js`. The price format of the catalog forms (Colombian: thousands with a point, decimals with a comma).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { money } from '../../resources/js/config/orders.js';
import { caretAfter, digitsBefore, group, parsePrice, priceToDisplay } from '../../resources/js/lib/price.js';

test('thousands are separated with points from four digits up', () => {
    assert.equal(group('800'), '800');
    assert.equal(group('8000'), '8.000');
    assert.equal(group('800000'), '800.000');
    assert.equal(group('1500000'), '1.500.000');
    assert.deepEqual(['800', '8000', '800000', '1500000'].map((typed) => parsePrice(typed).display), ['800', '8.000', '800.000', '1.500.000']);
});

test('what is sent to the server is a plain number: no separators ever reach the stored value', () => {
    assert.equal(parsePrice('1.500.000').value, '1500000');
    assert.equal(parsePrice('8.000').value, '8000');
    assert.equal(parsePrice('4.000,50').value, '4000.50');
    assert.equal(parsePrice('4.000,5').value, '4000.5');
    assert.equal(parsePrice('4.000,').value, '4000', 'a trailing comma is still an integer');
    assert.equal(parsePrice('').value, '');
    assert.equal(parsePrice('abc').value, '');
});

test('typing with a comma starts the decimals, a point is only a thousands separator, and decimals stop at two', () => {
    assert.equal(parsePrice('1500,757').display, '1.500,75');
    assert.equal(parsePrice('1.5').display, '15', 'a point is not a decimal mark here');
    assert.equal(parsePrice(',5').display, '0,5');
    assert.equal(parsePrice('0,5').value, '0.5');
    assert.equal(parsePrice('4000,').display, '4.000,', 'the comma stays while the person types the decimals');
});

test('leading zeros collapse and non-digits are dropped without duplicating digits', () => {
    assert.equal(parsePrice('007').display, '7');
    assert.equal(parsePrice('0').display, '0');
    assert.equal(parsePrice('$ 12a345').display, '12.345');
    assert.equal(parsePrice('12.345.6').display, '123.456', 'reformatting is idempotent over its own output');
    assert.equal(parsePrice(parsePrice('1500000').display).display, '1.500.000');
});

test('a number from the server is shown in the same format', () => {
    assert.equal(priceToDisplay('18500.00'), '18.500');
    assert.equal(priceToDisplay(4000.5), '4.000,5');
    assert.equal(priceToDisplay('4000.50'), '4.000,5');
    assert.equal(priceToDisplay('0.00'), '0');
    assert.equal(priceToDisplay(null), '');
    assert.equal(priceToDisplay(''), '');
});

test('the caret stays after the same digits when a separator appears or disappears', () => {
    // «1234|» (4 digits, caret at the end) becomes «1.234|»
    assert.equal(caretAfter('1.234', digitsBefore('1234', 4)), 5);
    // inserting a digit in the middle: «12|34» typed «125|34» → «12.534», caret after the 3 digits «125»
    const typed = '12534';
    const display = parsePrice(typed).display;
    assert.equal(display, '12.534');
    assert.equal(caretAfter(display, digitsBefore(typed, 3)), 4);
    assert.equal(caretAfter('', 0), 0);
    assert.equal(caretAfter('8.000', 0), 0);
    assert.equal(caretAfter('8.000', 99), 5);
});

// Intl separates the amount and the currency code with a no-break space.
const plain = (text) => text.replace(/ /g, ' ');

test('prices are listed with points from four digits on, in the currency of the record', () => {
    assert.equal(plain(money(800, 'COP')), '800 COP');
    assert.equal(plain(money(8000, 'COP')), '8.000 COP');
    assert.equal(plain(money(1500000, 'COP')), '1.500.000 COP');
    assert.equal(plain(money('4000.50', 'COP')), '4.000,50 COP');
});

test('the caret follows the person across the decimal comma too', async () => {
    const { caretFor } = await import('../../resources/js/lib/price.js');
    const typed = '4.000,5';
    assert.equal(caretFor(typed, typed.length, parsePrice(typed).display), 7);
    assert.equal(caretFor('4000,', 5, parsePrice('4000,').display), 6, 'right after the comma');
    assert.equal(caretFor('1234', 4, parsePrice('1234').display), 5);
    assert.equal(caretFor('12534', 3, parsePrice('12534').display), 4);
});
