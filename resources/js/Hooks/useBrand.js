import { usePage } from '@inertiajs/react';

/** The visible brand of the platform (config/branding.php, shared by the server). The fallback only exists for a page rendered without the shared props. */
export default function useBrand() {
    return usePage().props.brand || 'Ava Platform';
}
