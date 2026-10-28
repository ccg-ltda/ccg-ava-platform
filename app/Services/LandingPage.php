<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\HttpExceptionInterface;
use Throwable;

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
        ['view-services', 'service_catalog', 'services.index'],
        ['view-locations', 'locations', 'locations.index'],
        ['view-professionals', 'professionals', 'professionals.index'],
        ['view-bookings', 'bookings', 'bookings.index'],
        ['view-customers', 'customers', 'customers.index'],
        ['view-campaigns', 'campaigns', 'campaigns.index'],
        ['view-orders', 'orders', 'orders.index'],
        ['view-catalog', 'product_catalog', 'catalog.index'],
        ['manage-users', 'users_roles', 'users.index'],
        ['view-audit', 'audit', 'audit.index'],
        ['view-settings', 'settings', 'settings.index'],
        ['view-integrations', 'integrations', 'integrations.index'],
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

    /** Pages that answer data or files, not a screen: asking for one as a page (a prefetch, a poll sent without JSON headers) is never a place to land. */
    private const NOT_A_SCREEN = '/(\.alerts|\.thread|\.attachments?|\.availability|\.image|\.logo|\.manual|\.avatar|\.photo|\.download|\.export|\.pdf|\.csv|\.search)$/';

    /**
     * Where to go right after signing in. Laravel remembers the page a GUEST asked for (`url.intended`), but in a multi-Workspace app that page may belong to ANOTHER account
     * or Workspace (a tab left open on the previous session keeps asking for its pages), and going there sent a person with a valid login to a 403 on the first navigation
     * while the Dashboard, reached by reloading, worked. The remembered page is therefore only honoured when this account, in this Workspace, could open it: a screen of
     * this application, on this host, with no record id (an id may belong to another Workspace), whose own route checks (permissions and plan modules) the account passes.
     * Anything else is dropped and the landing is the first page the role and the plan allow. This only chooses where to START: every request is still authorized by the server.
     *
     * @param  list<string>  $permissions  what the user's role grants in the Workspace
     * @param  list<string>  $modules  what the Workspace's plan includes
     * @return array{0: string, 1: bool} the path to go to and whether it is the remembered page
     */
    public function afterLogin(?string $intended, string $host, array $permissions, array $modules): array
    {
        $landing = route($this->route($permissions, $modules), absolute: false);

        if (! is_string($intended) || $intended === '' || ! $this->mayOpen($intended, $host, $permissions, $modules)) {
            return [$landing, false];
        }

        return [parse_url($intended, PHP_URL_PATH) ?: $landing, true];
    }

    private function mayOpen(string $url, string $host, array $permissions, array $modules): bool
    {
        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || (isset($parts['host']) && strcasecmp($parts['host'], $host) !== 0) || ! str_starts_with((string) ($parts['path'] ?? ''), '/')) {
            return false;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($parts['path'], 'GET'));
        } catch (HttpExceptionInterface|Throwable) {
            return false;
        }

        return $this->screen($route) && $this->passes($route, $permissions, $modules);
    }

    /** A screen that needs a signed-in account bound to a Workspace: the only pages a login can lead to. */
    private function screen(LaravelRoute $route): bool
    {
        $name = (string) $route->getName();
        $middleware = array_map(fn ($m) => is_string($m) ? explode(':', $m)[0] : '', $route->gatherMiddleware());

        return $name !== '' && $route->parameterNames() === [] && ! str_starts_with($route->uri(), 'api/') && preg_match(self::NOT_A_SCREEN, $name) !== 1
            && in_array('auth', $middleware, true) && in_array('workspace', $middleware, true);
    }

    /** The checks the route itself makes, repeated for THIS account: every restrictive one must pass and an unknown one counts as a refusal. */
    private function passes(LaravelRoute $route, array $permissions, array $modules): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name, $arguments] = array_pad(explode(':', $middleware, 2), 2, '');
            $arguments = $arguments === '' ? [] : explode(',', $arguments);

            $ok = match ($name) {
                'workspace.permission' => array_intersect($arguments, $permissions) !== [],
                'workspace.module' => array_intersect($arguments, $modules) !== [],
                // Platform administration, abilities, roles and any other restrictive check this class does not know: not a place to land on.
                'workspace.platform', 'can', 'role', 'permission', 'role_or_permission', 'signed', 'agent' => false,
                default => true,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
