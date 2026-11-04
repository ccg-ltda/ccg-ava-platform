/**
 * Pure helpers of the skeleton loading (config/skeleton.php is the one map; the server shares it as the `skeleton` prop). Testable without a browser.
 */

/** The variant of the page at `path` (`/orders/12?x=1` -> `table`): the first segment decides; a path that is not listed uses the default. */
export function skeletonFor(path, { paths = {}, default: fallback = 'table' } = {}) {
    const first = String(path ?? '').split(/[?#]/)[0].split('/').filter(Boolean)[0] ?? '';

    return paths[first] ?? fallback;
}

/**
 * Whether a visit is a NAVIGATION to another page (worth a skeleton) and not a refresh of the one on screen: a different path, a plain GET, not a partial reload and not an
 * invisible prefetch. Filters, pagination, tabs and saving a form keep the current page and show their own busy state instead.
 */
export function isNavigation(visit, currentPath) {
    if (!visit || String(visit.method ?? 'get').toLowerCase() !== 'get' || visit.prefetch || (visit.only?.length ?? 0) > 0 || (visit.except?.length ?? 0) > 0) return false;

    const target = visit.url instanceof URL ? visit.url.pathname : String(visit.url ?? '').split(/[?#]/)[0];

    return target !== '' && target !== currentPath;
}
