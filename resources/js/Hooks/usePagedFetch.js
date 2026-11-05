import axios from 'axios';
import { useEffect, useState } from 'react';

/**
 * Server-side paging of a JSON list that lives in a modal (the same keys as App\Services\ListPagination: `<section>_page` / `<section>_per_page`).
 * Loads ONE page at a time; `rows` is null while the first page loads. `read` picks the rows out of the response body.
 */
export default function usePagedFetch({ url, section, read, perPage = 10 }) {
    const [state, setState] = useState({ rows: null, meta: null });
    const [page, setPage] = useState(1);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        let current = true;

        setLoading(true);
        axios
            .get(url, { params: { [`${section}_page`]: page, [`${section}_per_page`]: perPage } })
            .then((response) => current && (setFailed(false), setState({ rows: read(response.data), meta: response.data.meta })))
            .catch(() => current && setFailed(true))
            .finally(() => current && setLoading(false));

        return () => {
            current = false;
        };
    }, [url, section, page, perPage]);

    return { ...state, failed, loading, onPage: setPage };
}
