<?php

namespace App\Services;

/**
 * Where a user lands after signing in: the Dashboard when their role allows it, otherwise the first page of the menu
 * their role can open (the same order as `resources/js/config/navigation.js`), and their profile, which every member
 * can open, when the role grants nothing else. Without this a role that lacks `view-dashboard` would land on a 403.
 */
class LandingPage
{
    /** Permission => route name, in menu order. */
    private const PAGES = [
        'view-dashboard' => 'dashboard',
        'view-chatbots' => 'chatbots.index',
        'view-conversations' => 'conversations.index',
        'manage-settings' => 'settings.index',
        'manage-users' => 'users.index',
    ];

    /** @param  list<string>  $permissions */
    public function route(array $permissions): string
    {
        foreach (self::PAGES as $permission => $route) {
            if (in_array($permission, $permissions, true)) {
                return $route;
            }
        }

        return 'profile.edit';
    }
}
