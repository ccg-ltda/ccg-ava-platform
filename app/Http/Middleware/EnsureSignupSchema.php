<?php

namespace App\Http\Middleware;

use App\Services\SignupCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public sign-up needs the tables and columns of the migration `create_public_signup_tables`. Code can be deployed before that migration is applied
 * (it is applied deliberately, never automatically): until then every step but the wizard's first screen answers a controlled 503 instead of a SQL error.
 */
class EnsureSignupSchema
{
    public function __construct(private readonly SignupCatalog $catalog) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->catalog->ready() && $request->route()?->getName() !== 'signup.index') {
            abort(503, 'El registro todavía no está disponible.');
        }

        return $next($request);
    }
}
