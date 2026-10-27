/**
 * Line-by-line comparison of two texts (longest common subsequence) for the side-by-side review of a proposed instruction change.
 * Returns the lines of the NEW text in order, each marked `same`, `added`, or — for lines that only exist in the old text — `removed`.
 */
export function diffLines(before, after) {
    const a = String(before ?? '') === '' ? [] : String(before).split('\n');
    const b = String(after ?? '') === '' ? [] : String(after).split('\n');
    const table = Array.from({ length: a.length + 1 }, () => new Array(b.length + 1).fill(0));

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i][j] = a[i] === b[j] ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }

    const out = [];
    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            out.push({ type: 'same', text: a[i] });
            i++;
            j++;
        } else if (table[i + 1][j] >= table[i][j + 1]) {
            out.push({ type: 'removed', text: a[i++] });
        } else {
            out.push({ type: 'added', text: b[j++] });
        }
    }

    while (i < a.length) out.push({ type: 'removed', text: a[i++] });
    while (j < b.length) out.push({ type: 'added', text: b[j++] });

    return out;
}

/** Whether the comparison contains any change. */
export const hasChanges = (lines) => lines.some((line) => line.type !== 'same');
