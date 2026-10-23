// Pure helpers of the entity picker (tested with `npm run test:js`).

/** Adds the value when absent, removes it when present; never duplicates. */
export function toggleValue(values, value) {
    return values.includes(value) ? values.filter((current) => current !== value) : [...values, value];
}
