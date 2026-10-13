import { usePage } from '@inertiajs/react';

/**
 * What the signed-in user can see in the active Workspace, as the server shared it: the permissions of their role, the
 * modules their Workspace's plan includes and whether they administer the platform (plans and catalog). It decides what
 * the interface offers; the server still enforces every route.
 */
export default function useAccess() {
    const { auth, modules, platform } = usePage().props;

    return { permissions: auth.user?.permissions ?? [], modules: modules ?? [], platform: Boolean(platform) };
}
