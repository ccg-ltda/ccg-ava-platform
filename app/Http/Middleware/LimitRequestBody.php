<?php

namespace App\Http\Middleware;

use App\Support\ApiProtection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a body larger than the limit of its surface (`config/api_protection.php`, in KB) with 413 BEFORE anything reads or parses it. The declared Content-Length is checked
 * first (cheap, no read); a request that declares none (chunked) is checked against what it really sent. Usage: `body.limit:agent|widget|booking|provider`.
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        $max = (int) config("api_protection.{$surface}.max_body_kb") * 1024;

        if ($max > 0 && ! $request->isMethodSafe()) {
            $declared = $request->headers->get('Content-Length');
            $size = $declared !== null && ctype_digit($declared) ? (int) $declared : strlen($request->getContent());

            if ($size > $max) {
                ApiProtection::logExceeded($request, 'body');

                return new JsonResponse(['message' => 'El cuerpo de la solicitud es demasiado grande.'], 413);
            }
        }

        return $next($request);
    }
}
