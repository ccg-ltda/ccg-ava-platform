<?php

/*
 * Security headers of every response (App\Http\Middleware\SecurityHeaders). Laravel is the only place that sets them for dynamic pages (the nginx of the
 * `app` image does not add them), so there are no duplicated or contradictory values; a header a controller already set (for example `nosniff` on a
 * private file) is kept as it is.
 */
return [
    /* Master switch (tests and rare debugging). Leave it on. */
    'enabled' => (bool) env('SECURITY_HEADERS', true),

    /* Sends the origin (never the path or the query, where reset and invitation tokens live) to other sites, and the full URL only within Ava. */
    'referrer_policy' => 'strict-origin-when-cross-origin',

    /*
     * Strict-Transport-Security. Sent ONLY on requests Laravel sees as HTTPS (behind a balancer that means TRUSTED_PROXIES must be right) and only when
     * `enabled` is true; by default that is production alone, so a local HTTP environment never gets it. `include_subdomains` and `preload` stay off:
     * turn them on only after confirming that every subdomain of the domain is served over HTTPS (preload also needs a max-age of a year and a manual
     * submission, and it is very hard to undo). A browser keeps the policy for `max_age` seconds after seeing it, so raise the value in steps.
     */
    'hsts' => [
        'enabled' => env('SECURITY_HSTS') === null ? null : filter_var(env('SECURITY_HSTS'), FILTER_VALIDATE_BOOLEAN),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 15552000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    /*
     * Content-Security-Policy. `report-only` (default) sends Content-Security-Policy-Report-Only: the browser reports what the policy would block and
     * blocks nothing. `enforce` blocks. `off` sends none. Move to `enforce` only after reviewing the browser console (or `report_uri`) on every screen of the
     * application, authenticated ones included. No CSP is sent while the Vite dev server is running (public/hot exists).
     *
     * Inventory behind the directives (see RECOMENDACIONES.md §70):
     *  - script-src: the application's own bundles (`'self'`, with the per-request nonce Vite and Ziggy print), the inline theme script and the Ziggy
     *    route table (nonce); three.js for the login background is bundled (no CDN). No `unsafe-inline` and no `unsafe-eval` for scripts.
     *  - style-src: own CSS (Inter is bundled, no Google Fonts); the inline <style> of Inertia's progress bar carries the nonce (createInertiaApp `nonce`). React writes `style="…"` attributes, which a nonce cannot cover, so `style-src-attr` alone
     *    allows `'unsafe-inline'` (it cannot run script).
     *  - font-src: own files. img-src: own images, data: and blob: URIs (previews, avatars). connect-src: same origin (Inertia and fetch).
     *  - form-action: same origin plus the Wompi checkout the sign-up redirects to. frame-ancestors: nobody can frame Ava (the widget is a script, not a frame).
     */
    'csp' => [
        'mode' => env('SECURITY_CSP_MODE', 'report-only'), // off | report-only | enforce
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),
        // No third-party host: scripts, styles and fonts are the application's own. Add a host here only for a resource that really needs it (and review its privacy).
        'script_hosts' => [],
        'style_hosts' => [],
        'font_hosts' => [],
        'form_hosts' => ['https://checkout.wompi.co'],
    ],
];
