<?php

namespace App\Services;

/**
 * Where a user lands after signing in: the Dashboard when their role allows it, otherwise the first page of the menu
 * their role can open (the same order as `resources/js/config/navigation.js`), and their profile, which every member
 * can open, when the role grants nothing else. A page only counts when the Workspace's plan includes its module too.
 * Without this a role that lacks `view-dashboard` (or a plan without it) would land on a 403.
 */
class LandingPage
{
    /** [permission, module, route name], in menu order. */
    private const PAGES = [
        ['view-dashboard', 'dashboard', 'dashboard'],
        ['view-chatbots', 'assistants', 'chatbots.index'],
        ['view-conversations', 'conversations', 'conversations.index'],
        ['view-bookings', 'service_catalog', 'services.index'],
        ['view-bookings', 'professionals', 'professionals.index'],
        ['view-bookings', 'bookings', 'bookings.index'],
        ['view-customers', 'customers', 'customers.index'],
        ['view-campaigns', 'campaigns', 'campaigns.index'],
        ['view-orders', 'orders', 'orders.index'],
        ['view-catalog', 'product_catalog', 'catalog.index'],
        ['manage-users', 'users_roles', 'users.index'],
        ['manage-settings', 'audit', 'audit.index'],
        ['manage-settings', 'settings', 'settings.index'],
        ['manage-settings', 'integrations', 'integrations.index'],
    ];

    /**
     * @param  list<string>  $permissions  what the user's role grants in the Workspace
     * @param  list<string>  $modules  what the Workspace's plan includes
     */
    public function route(array $permissions, array $modules): string
    {
        foreach (self::PAGES as [$permission, $module, $route]) {
            if (in_array($permission, $permissions, true) && in_array($module, $modules, true)) {
                return $route;
            }
        }

        return 'profile.edit';
    }
}
