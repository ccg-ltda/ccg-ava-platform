<?php

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\EnforceAbsoluteSession;
use App\Http\Middleware\EnforceApiProtection;
use App\Http\Middleware\EnsureLegalAcceptance;
use App\Http\Middleware\EnsurePlatformAdministration;
use App\Http\Middleware\EnsureWorkspaceContext;
use App\Http\Middleware\EnsureWorkspaceModule;
use App\Http\Middleware\EnsureWorkspacePermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\SafeThrottleRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TraceAgentTool;
use App\Support\ApiProtection;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // First: an expired session is signed out BEFORE anything else (the legal check, Inertia's shared props) looks at the user.
            EnforceAbsoluteSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureLegalAcceptance::class,
        ]);

        // Security headers (config/security.php) on every response, web and api; the CORS headers come from Laravel's own HandleCors (config/cors.php).
        $middleware->append(SecurityHeaders::class);

        // A session opened before a password change or reset is closed on its next request (the guard compares the password hash).
        $middleware->authenticateSessions();

        // Behind a load balancer / reverse proxy: TRUSTED_PROXIES="*" or a comma-separated list. Unset = trust none.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') === '*' ? '*' : array_filter(explode(',', (string) env('TRUSTED_PROXIES'))),
        );

        // Authorization runs BEFORE route-model binding. Otherwise a person who may not use a route still learns whether an id exists
        // (404 for a missing record, 403 for an existing one). None of these middleware reads a bound model, and `can:` is only used with
        // model-less gates (routes/web.php), so this changes the status code of a refused request and nothing else. This is Laravel's own
        // priority list (Illuminate\Foundation\Http\Kernel) with Authorize and Ava's four checks moved in front of SubstituteBindings.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            EnforceAbsoluteSession::class, // before the route's `auth`: an expired session must already be a guest when `auth` looks at it
            AuthenticatesRequests::class,
            SafeThrottleRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            AuthenticateAgent::class, // sets the Workspace of the agent API that EnsureWorkspaceModule reads
            EnsureWorkspaceContext::class,
            EnforceApiProtection::class, // after the token (agent API) has named the Workspace: its own buckets and concurrency cap
            EnsurePlatformAdministration::class,
            EnsureWorkspaceModule::class,
            EnsureWorkspacePermission::class,
            Authorize::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'agent' => AuthenticateAgent::class,
            'agent.trace' => TraceAgentTool::class,
            'api.protect' => EnforceApiProtection::class,
            'throttle.safe' => SafeThrottleRequests::class,
            'body.limit' => LimitRequestBody::class,
            'workspace' => EnsureWorkspaceContext::class,
            'workspace.permission' => EnsureWorkspacePermission::class,
            'workspace.module' => EnsureWorkspaceModule::class,
            'workspace.platform' => EnsurePlatformAdministration::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A failed validation flashes the submitted input into the session: never credentials of an integration nor a recovery token.
        $exceptions->dontFlash(['auth_secret', 'headers', 'query', 'body', 'token']);

        // Every 429 of the application is logged once, whoever produced it (a named limiter, a Workspace bucket or the concurrency cap): fixed facts, never a token or a body.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->attributes->get('api_limit_logged')) {
                ApiProtection::logExceeded($request, 'rate', isset($e->getHeaders()['Retry-After']) ? (int) $e->getHeaders()['Retry-After'] : null);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
