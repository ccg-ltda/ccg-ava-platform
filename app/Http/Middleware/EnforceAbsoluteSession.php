<?php

namespace App\Http\Middleware;

use App\Services\AbsoluteSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the absolute session lifetime (App\Services\AbsoluteSession). It runs early in the web group: when the limit is reached it signs the person out and lets
 * the request continue as a guest, so the route's own `auth` middleware answers exactly as it does for an idle session (401 JSON for API clients, a redirect to the
 * sign-in for pages and Inertia requests; no HTML for a JSON client, no loop, and a form posted with the old session gets the sign-in, not a 419).
 */
class EnforceAbsoluteSession
{
    public function __construct(private readonly AbsoluteSession $absolute) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard();
        $user = $guard->user(); // may restore the session from a remember cookie
        $session = $request->session();

        if ($user === null) {
            $session->forget(AbsoluteSession::KEY);

            return $next($request);
        }

        $started = $session->get(AbsoluteSession::KEY);

        if ($started === null) {
            // A session restored from the remember cookie inherits the original start; one that predates this rule (no start recorded) starts counting now.
            $started = method_exists($guard, 'viaRemember') && $guard->viaRemember() ? $this->absolute->inherited($user) : now()->timestamp;

            if ($started !== null) {
                $session->put(AbsoluteSession::KEY, $started);
            }
        }

        if ($started === null || $this->absolute->expired((int) $started)) {
            $this->absolute->forget($user);
            $guard->logout(); // also cycles the remember token, so the cookie cannot bring the session back
            $session->invalidate();
            $session->regenerateToken();
        }

        return $next($request);
    }
}
