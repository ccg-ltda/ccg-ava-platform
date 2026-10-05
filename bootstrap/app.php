<?php

use App\Http\Middleware\EnsureWorkspaceContext;
use App\Http\Middleware\EnsureWorkspacePermission;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Behind a load balancer / reverse proxy: TRUSTED_PROXIES="*" or a comma-separated list. Unset = trust none.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') === '*' ? '*' : array_filter(explode(',', (string) env('TRUSTED_PROXIES'))),
        );

        $middleware->alias([
            'workspace' => EnsureWorkspaceContext::class,
            'workspace.permission' => EnsureWorkspacePermission::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A failed validation flashes the submitted input into the session: never credentials of an integration.
        $exceptions->dontFlash(['auth_secret', 'headers', 'query', 'body']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
