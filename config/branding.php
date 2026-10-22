<?php

/*
 * The visible brand of the platform's emails, in one place. The legal name, internal identifiers and historical records are NOT here and are never replaced
 * by it. The rebranding of the whole interface is a separate task: until then the name is the one the platform already shows, and it is changed with
 * `BRAND_NAME` (for example "Zonno by CCG") without touching code.
 */
return [
    'name' => env('BRAND_NAME', 'Ava Platform'),

    // Where the email footer tells people to write. Nothing is invented: without a configured address the support line is simply not printed.
    'support_email' => env('BRAND_SUPPORT_EMAIL', env('LEGAL_CONTACT_EMAIL')),
];
