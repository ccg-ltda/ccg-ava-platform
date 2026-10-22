import { useEffect, useState } from 'react';

const KEY = 'ava.sidebar.collapsed';

/**
 * Whether the sidebar is collapsed to icons on large screens. A per-browser convenience (the same kind of remembered choice as the theme): wrapped in try/catch
 * because storage can be unavailable, and the page works the same without it. It never decides what the menu offers: the modules and permissions do.
 */
export default function useSidebarCollapsed() {
    const [collapsed, setCollapsed] = useState(false);

    useEffect(() => {
        try {
            setCollapsed(window.localStorage.getItem(KEY) === '1');
        } catch {
            /* no stored choice */
        }
    }, []);

    const toggle = () => setCollapsed((current) => {
        const next = !current;

        try {
            window.localStorage.setItem(KEY, next ? '1' : '0');
        } catch {
            /* the choice just is not remembered */
        }

        return next;
    });

    return [collapsed, toggle];
}
