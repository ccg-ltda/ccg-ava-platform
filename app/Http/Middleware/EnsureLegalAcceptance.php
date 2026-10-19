<?php

namespace App\Http\Middleware;

use App\Legal\LegalDocuments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When acceptance is required (config/legal.php, off by default until CCG confirms the texts), a signed-in account with a
 * required document not yet accepted at its current version can only read the legal pages, accept, or sign out. It decides
 * nothing about Workspaces, roles or permissions: those checks run afterwards, unchanged.
 */
class EnsureLegalAcceptance
{
    private const ALLOWED_ROUTES = ['legal.index', 'legal.show', 'legal.accept', 'legal.accept.store', 'logout'];

    public function __construct(private readonly LegalDocuments $legal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('legal.acceptance_required') || ! $user || in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        // Without the record's tables there is nowhere to leave the evidence: refuse, loudly, instead of letting anyone through unrecorded.
        if (! $this->legal->available()) {
            abort(503, LegalDocuments::UNAVAILABLE);
        }

        // Agent, webhook and widget traffic carries no session user; the check above lets it through.
        if ($this->legal->pendingFor($user)->isNotEmpty()) {
            return redirect()->guest(route('legal.accept'));
        }

        return $next($request);
    }
}
