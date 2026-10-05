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
