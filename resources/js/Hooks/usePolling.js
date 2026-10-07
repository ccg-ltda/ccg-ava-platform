import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Reloads only the given props every `ms` while the page is visible (the server is the source; nothing is guessed in
 * the browser). Paused in a hidden tab, and refreshed once when the tab becomes visible again.
 */
export default function usePolling(only, ms = 15000) {
    useEffect(() => {
        const reload = () => document.visibilityState === 'visible' && router.reload({ only, preserveScroll: true, preserveState: true });
        const timer = setInterval(reload, ms);

        document.addEventListener('visibilitychange', reload);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', reload);
        };
    }, [ms, only.join(',')]); // eslint-disable-line react-hooks/exhaustive-deps
}
