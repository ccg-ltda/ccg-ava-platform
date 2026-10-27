// Pure helpers of the entity picker (tested with `npm run test:js`).

/** Adds the value when absent, removes it when present; never duplicates. */
export function toggleValue(values, value) {
    return values.includes(value) ? values.filter((current) => current !== value) : [...values, value];
}

/**
 * The «all» scope of a picker whose empty list means «every one, including future ones» (the server stores no id for it). From the current list and the value
 * the person toggled, the next list: toggling one while «all» is on EXCLUDES it (an explicit list of the others); picking every offered value one by one
 * collapses back to «all» (an empty list); excluding the last remaining one stays «all», because an empty list can not mean «none».
 * @param {Array} current the ids stored ([] = all) @param {*} toggled the id the person clicked @param {Array} offered the values the person may choose
 */
export function toggleInScope(current, toggled, offered) {
    const explicit = current.length === 0 ? [...offered] : current;
    const next = toggleValue(explicit, toggled).filter((value) => offered.includes(value) || current.includes(value));

    return next.length === 0 || offered.every((value) => next.includes(value)) ? [] : next;
}
