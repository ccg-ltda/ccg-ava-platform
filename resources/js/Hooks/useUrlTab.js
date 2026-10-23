import { useState } from 'react';

/**
 * Selected tab of a module, kept in the URL (?tab=<id>) so it survives reloads and can be linked.
 * `ids` lists the tab ids in display order; returns [selectedIndex, select(index)].
 *
 * The choice is remembered by ID, not by position: when the tabs change under a mounted page (a plan without a module, another Workspace chosen, a permission
 * that is gone) the same tab stays selected if it still exists and the first one is used if not, so the index can never point outside the list.
 */
export default function useUrlTab(ids) {
    const [selectedId, setSelectedId] = useState(() => new URLSearchParams(window.location.search).get('tab'));
    const selected = Math.max(ids.indexOf(selectedId), 0);

    const select = (index) => {
        const id = ids[index];

        if (id === undefined) return;

        setSelectedId(id);

        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.replaceState(window.history.state, '', url);
    };

    return [selected, select];
}
