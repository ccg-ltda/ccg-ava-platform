import { useEffect, useState } from 'react';

const KEY = 'ava.sidebar.collapsed';
const NARROW = '(max-width: 1023px)';

/** What the user chose last (`true` collapsed, `false` expanded), or `null` when they have not chosen. Storage can be unavailable: then nothing is remembered. */
function stored() {
    try {
        const value = window.localStorage.getItem(KEY);

        return value === null ? null : value === '1';
    } catch {
        return null;
    }
}

/**
 * Whether the sidebar is collapsed to icons on desktop widths (from `md` up). The choice is a per-browser convenience (the same kind of remembered choice as the
 * theme) and is wrapped in try/catch because storage can be unavailable: the page works the same without it. Until the user chooses, a narrow window (a half
 * screen) starts collapsed to leave room for the page and a wide one starts expanded; once they choose, the toggle works at every width and the choice wins.
 * It never decides what the menu offers: the modules and permissions do.
 */
export default function useSidebarCollapsed() {
    const [choice, setChoice] = useState(null);
    const [narrow, setNarrow] = useState(false);

    useEffect(() => {
        setChoice(stored());

        const query = window.matchMedia(NARROW);
        const update = () => setNarrow(query.matches);

        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    const collapsed = choice ?? narrow;

    const toggle = () => {
        const next = !collapsed;

        setChoice(next);

        try {
            window.localStorage.setItem(KEY, next ? '1' : '0');
        } catch {
            /* the choice just is not remembered */
        }
    };

    return [collapsed, toggle];
}
