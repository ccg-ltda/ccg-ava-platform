<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the security headers described in config/security.php to every response. It never overwrites a header a controller already set, and it runs for
 * the web and api groups alike (a JSON answer needs `nosniff` too). The nonce of the Content-Security-Policy is created per request, handed to Vite
 * and to the layout, and never reused.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.enabled')) {
            return $next($request);
        }

        $nonce = $this->cspWanted() ? base64_encode(random_bytes(16)) : null;

        if ($nonce !== null) {
            Vite::useCspNonce($nonce);
            View::share('cspNonce', $nonce);
        }

        $response = $next($request);

        $this->set($response, 'X-Content-Type-Options', 'nosniff');
        $this->set($response, 'X-Frame-Options', 'SAMEORIGIN');
        $this->set($response, 'Referrer-Policy', (string) config('security.referrer_policy'));
        $this->set($response, 'Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure() && ($hsts = $this->hsts())) {
            $this->set($response, 'Strict-Transport-Security', $hsts);
        }

        if ($nonce !== null && $this->isHtml($response)) {
            $header = config('security.csp.mode') === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $this->set($response, $header, $this->policy($nonce));
        }

        return $response;
    }

    private function set(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }

    /** The CSP is skipped when it is off and while the Vite dev server runs (it serves scripts and a websocket from another origin). */
    private function cspWanted(): bool
    {
        return in_array(config('security.csp.mode'), ['report-only', 'enforce'], true) && ! Vite::isRunningHot();
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    /** HSTS is for production by default, never for a plain-HTTP local environment (this method is only reached on a request Laravel sees as HTTPS). */
    private function hsts(): ?string
    {
        $configured = config('security.hsts.enabled');
        $enabled = $configured ?? app()->isProduction();
        $maxAge = (int) config('security.hsts.max_age');

        if (! $enabled || $maxAge <= 0) {
            return null;
        }

        $value = "max-age={$maxAge}";
        $subdomains = (bool) config('security.hsts.include_subdomains');

        if ($subdomains) {
            $value .= '; includeSubDomains';
        }

        // `preload` is honored only when its browser-list requirements are met: includeSubDomains and at least a year.
        if (config('security.hsts.preload') && $subdomains && $maxAge >= 31536000) {
            $value .= '; preload';
        }

        return $value;
    }

    private function policy(string $nonce): string
    {
        $csp = (array) config('security.csp');
        $hosts = fn (string $key): string => implode(' ', (array) ($csp[$key] ?? []));
        $join = fn (string ...$parts): string => trim(implode(' ', array_filter($parts, fn (string $part) => $part !== '')));

        $directives = [
            "default-src 'self'",
            $join("script-src 'self' 'nonce-{$nonce}'", $hosts('script_hosts')),
            $join("style-src 'self' 'nonce-{$nonce}'", $hosts('style_hosts')),
            "style-src-attr 'unsafe-inline'",
            $join("font-src 'self'", $hosts('font_hosts')),
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "connect-src 'self'",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            $join("form-action 'self'", $hosts('form_hosts')),
            "frame-ancestors 'self'",
        ];

        if (is_string($csp['report_uri'] ?? null) && Str::startsWith($csp['report_uri'], ['/', 'https://'])) {
            $directives[] = 'report-uri '.$csp['report_uri'];
        }

        return implode('; ', $directives);
    }
}
