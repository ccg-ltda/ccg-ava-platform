<?php

/*
 * The visible brand of the platform (interface, tab title, emails), in one place. The legal name, internal identifiers and historical records are NOT here and are never replaced
 * by it. `name` is the platform name; `short` is the compact form for tight spaces (sidebar, top bar). Both can be changed with `BRAND_NAME` and
 * `BRAND_SHORT_NAME` without touching code.
 */
return [
    'name' => env('BRAND_NAME', 'Zonno Platform by CCG'),

    'short' => env('BRAND_SHORT_NAME', 'Zonno by CCG'),

    // Where the email footer tells people to write. Nothing is invented: without a configured address the support line is simply not printed.
    'support_email' => env('BRAND_SUPPORT_EMAIL', env('LEGAL_CONTACT_EMAIL')),
];
