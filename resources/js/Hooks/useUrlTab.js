import { useState } from 'react';

/**
 * Selected tab of a module, kept in the URL (?tab=<id>) so it survives reloads and can be linked.
 * `ids` lists the tab ids in display order; returns [selectedIndex, select(index)].
 */
export default function useUrlTab(ids) {
    const [selected, setSelected] = useState(() => {
        const requested = new URLSearchParams(window.location.search).get('tab');

        return Math.max(ids.indexOf(requested), 0);
    });

    const select = (index) => {
        setSelected(index);

        const url = new URL(window.location.href);
        url.searchParams.set('tab', ids[index]);
        window.history.replaceState(window.history.state, '', url);
    };

    return [selected, select];
}
