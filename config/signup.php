<?php

/*
 * Public sign-up of new customers (/registro). Only security limits live here; nothing commercial (prices, plans, solutions, limits) is configured in
 * files: it comes from the Planes administration (plans, module categories, modules).
 */
return [
    // One-time email code: validity, wrong attempts allowed per code, seconds before it can be sent again, and sends per email per hour.
    'code_ttl_minutes' => (int) env('SIGNUP_CODE_TTL_MINUTES', 15),
    'code_max_attempts' => 5,
    'code_resend_seconds' => 60,
    'code_sends_per_hour' => 5,

    // How long a half-finished sign-up is kept before it expires, in hours.
    'draft_ttl_hours' => (int) env('SIGNUP_DRAFT_TTL_HOURS', 48),

    // The password of the first administrator.
    'password_min' => 10,
];
