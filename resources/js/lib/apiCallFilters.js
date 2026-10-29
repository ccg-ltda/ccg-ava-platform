/** Filters of the API call history («Historial de llamados»): the query the server understands and what the page shows about them. */
export const EMPTY_FILTERS = { integration: '', operation: '', outcome: '', status: '', from: '', to: '' };

/** The query string parameters: only the filters with a value, plus the page (the server ignores anything else). */
export function callQuery(filters, page = 1) {
    const params = Object.fromEntries(Object.entries(filters).filter(([key, value]) => key in EMPTY_FILTERS && value !== '' && value != null));

    return page > 1 ? { ...params, page } : params;
}

/** How many filters narrow the list right now (drives «Limpiar filtros» and its counter). */
export function activeFilterCount(filters) {
    return Object.keys(EMPTY_FILTERS).filter((key) => filters[key] !== '' && filters[key] != null).length;
}

/** The HTTP code field only accepts digits (at most three), as the server does. */
export function cleanStatus(value) {
    return String(value ?? '').replace(/\D/g, '').slice(0, 3);
}
