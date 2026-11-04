import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { isNavigation, skeletonFor } from '@/lib/skeleton';

const IDLE = { variant: null, failed: false, url: null };

/**
 * What the content area shows while another page is on its way. A navigation that answers quickly shows nothing extra (no flash); one that takes longer than `delay` ms shows the
 * skeleton of the module being opened (`{ variant }`), and one that has not arrived after `giveUp` ms stops showing it and says so (`{ failed, url }`, so the page offers a retry): a skeleton
 * is never left on screen forever. Only real navigations count (see `isNavigation`): filters, pagination, tabs and saving a form keep the page and use their own busy state. The
 * request itself is never delayed or cancelled here; this only listens. `config` is the `skeleton` prop the server shares (config/skeleton.php).
 */
export default function useNavigationSkeleton(config) {
    const [state, setState] = useState(IDLE);

    useEffect(() => {
        let showTimer = null;
        let giveUpTimer = null;

        const clear = () => {
            clearTimeout(showTimer);
            clearTimeout(giveUpTimer);
            setState((current) => (current === IDLE ? current : IDLE));
        };

        const offStart = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (!isNavigation(visit, window.location.pathname)) return;

            clearTimeout(showTimer);
            clearTimeout(giveUpTimer);
            const url = visit.url instanceof URL ? visit.url.pathname + visit.url.search : String(visit.url);
            const variant = skeletonFor(url, config);

            showTimer = setTimeout(() => setState({ variant, failed: false, url }), config?.delay ?? 300);
            giveUpTimer = setTimeout(() => setState({ variant: null, failed: true, url }), config?.giveUp ?? 20000);
        });
        // `finish` comes after a success, an error, a cancelled visit and an exception alike: whatever happened, the skeleton goes.
        const offFinish = router.on('finish', clear);

        return () => {
            offStart();
            offFinish();
            clearTimeout(showTimer);
            clearTimeout(giveUpTimer);
        };
    }, [config?.delay, config?.giveUp]); // eslint-disable-line react-hooks/exhaustive-deps

    return state;
}
