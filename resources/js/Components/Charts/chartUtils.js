/** A round top for an axis (1, 2, 5, 10, 20, 50...) and evenly spaced ticks up to it. */
export function niceScale(max, ticks = 4) {
    if (!(max > 0)) return { top: ticks, ticks: Array.from({ length: ticks + 1 }, (_, i) => i) };

    const rough = max / ticks;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= rough);
    const top = step * ticks;

    return { top, ticks: Array.from({ length: ticks + 1 }, (_, i) => i * step) };
}

/** Indexes of at most `limit` evenly spread labels (the first and the last always included). */
export function spreadIndexes(count, limit) {
    if (count <= limit) return Array.from({ length: count }, (_, i) => i);

    const step = (count - 1) / (limit - 1);

    return [...new Set(Array.from({ length: limit }, (_, i) => Math.round(i * step)))];
}

export const MARGIN = { top: 12, right: 12, bottom: 28, left: 40 };

/**
 * Indexes of the labels of a time axis: ONE label every `step` points, always from the first, so the spacing is even and a skipped label never looks like a missing day
 * (every point still gets its own tick mark). `limit` is how many labels fit.
 */
export function labelIndexes(count, limit) {
    const step = Math.max(1, Math.ceil(count / Math.max(limit, 1)));

    return { step, indexes: Array.from({ length: Math.ceil(count / step) }, (_, i) => i * step) };
}

/** Margins with the left one sized to the widest value label of the vertical axis (11px text), so the numbers never touch the edge or get cut. */
export function axisMargin(tickLabels) {
    const widest = Math.max(0, ...tickLabels.map((label) => String(label).length));

    return { ...MARGIN, left: Math.max(MARGIN.left, Math.ceil(widest * 6.6) + 20) };
}
