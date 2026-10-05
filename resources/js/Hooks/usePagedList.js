import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Server-side paging of one list of the users module. Each list owns its query keys
 * (`page` / `per_page` for users, `<section>_page` / `<section>_per_page` for the rest, as in
 * App\Services\ListPagination), reloads only its own prop, and keeps its page size when changing page.
 *
 * `extra` are additional query params that must travel with every visit (e.g. the users search).
 * Spread `paginationProps` into <Pagination>; use `visit(params, { restart: true })` for a search/filter change.
 */
export default function usePagedList({ tab, section, only, meta, perPageOptions, extra = {} }) {
    const [loading, setLoading] = useState(false);

    const pageKey = section === 'users' ? 'page' : `${section}_page`;
    const sizeKey = section === 'users' ? 'per_page' : `${section}_per_page`;

    // Every visit keeps the list's current page and size; a change of size, search or filter passes
    // `restart` so the list starts again at page 1.
    const visit = (params = {}, { restart = false } = {}) =>
        router.get(route('users.index'), { tab, [sizeKey]: meta.per_page, [pageKey]: restart ? undefined : meta.current_page, ...extra, ...params }, {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    return {
        loading,
        visit,
        paginationProps: {
            meta,
            perPage: meta.per_page,
            perPageOptions,
            onPage: (page) => visit({ [pageKey]: page }),
            onPerPage: (size) => visit({ [sizeKey]: size }, { restart: true }),
        },
    };
}
