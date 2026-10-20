/**
 * Prices as a Colombian person writes and reads them: thousands separated with a point («8.000», «1.500.000») and the decimals, when there are any, with a
 * comma («4.000,50»). The input shows that format while the person types and the server receives a plain number («4000.50»): the separators are never part
 * of the stored value. Pure functions, testable without a browser. The server validates the number again (at most two decimals, never negative).
 */

const MAX_DECIMALS = 2;

/** Groups the digits of an integer string by thousands with points: «1500000» → «1.500.000». */
export const group = (digits) => digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

/**
 * What the person typed (any mix of digits, points and a comma) as the text to show and the number to send.
 * A comma starts the decimals (a point is only a thousands separator); anything that is not a digit is dropped; leading zeros collapse («007» → «7»);
 * decimals are cut at two. `value` is '' when nothing numeric was typed, otherwise «1500000» or «4000.5».
 *
 * @returns {{display: string, value: string}}
 */
export function parsePrice(typed) {
    const text = String(typed ?? '');
    const comma = text.indexOf(',');
    const integerPart = (comma === -1 ? text : text.slice(0, comma)).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const decimalPart = comma === -1 ? null : text.slice(comma + 1).replace(/\D/g, '').slice(0, MAX_DECIMALS);

    if (integerPart === '' && decimalPart === null) return { display: '', value: '' };

    const integer = integerPart === '' ? '0' : integerPart;
    const display = `${group(integer)}${decimalPart === null ? '' : `,${decimalPart}`}`;
    const value = decimalPart === null || decimalPart === '' ? integer : `${integer}.${decimalPart}`;

    return { display, value };
}

/** The text to show for a number that comes from the server («4000.50» or 4000.5 → «4.000,50» / «4.000,5»; 18500 → «18.500»). */
export function priceToDisplay(value) {
    if (value === null || value === undefined || value === '') return '';

    const [integer, decimals = ''] = String(value).split('.');
    const trimmed = decimals.replace(/0+$/, '');

    return parsePrice(trimmed === '' ? integer : `${integer},${trimmed}`).display;
}

/**
 * Where the caret goes after the text was reformatted: right after the same number of digits it had before (digits are what the person is typing; the
 * separators come and go). `digitsBefore` is how many digits preceded the caret in the text as typed.
 */
export function caretAfter(display, digitsBefore) {
    if (digitsBefore <= 0) return 0;

    let seen = 0;

    for (let index = 0; index < display.length; index += 1) {
        if (/\d/.test(display[index])) seen += 1;
        if (seen === digitsBefore) return index + 1;
    }

    return display.length;
}

/** How many digits precede `position` in `text`. */
export const digitsBefore = (text, position) => (text.slice(0, position).match(/\d/g) ?? []).length;

/**
 * The caret position in the reformatted `display` for a caret at `caret` in the `typed` text: after the same digits it had (the separators come and go),
 * and, when it was already past the decimal comma, after the same decimals.
 */
export function caretFor(typed, caret, display) {
    const comma = typed.indexOf(',');

    if (comma !== -1 && caret > comma) {
        const decimals = typed.slice(comma + 1, caret).replace(/\D/g, '').length;

        return Math.min(display.indexOf(',') + 1 + decimals, display.length);
    }

    return caretAfter(display, digitsBefore(typed, caret));
}
