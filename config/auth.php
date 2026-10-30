<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => (int) env('AUTH_PASSWORD_RESET_EXPIRE', 60),
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remembered Access
    |--------------------------------------------------------------------------
    |
    | How long "Recordar sesión" remembers the last email and Workspace (minutes).
    | The password is never remembered. See App\Services\RememberedAccess.
    |
    */

    /*
     * Failed-login limits (App\Http\Requests\Auth\LoginRequest). Per ACCOUNT and IP: `account_max_attempts` failures per `account_decay_seconds` for the same
     * e-mail from the same IP (the original limit). Per IP alone: `ip_max_attempts` failures per `ip_decay_seconds` across ALL e-mails, which stops one address from
     * trying many accounts (credential stuffing) without blocking the people behind a shared address after a few typos. A successful login clears the account limit,
     * never the IP one. Neither is configurable by environment on purpose: loosening them is a code review, not a typo in a file.
     */
    'login_throttle' => [
        'account_max_attempts' => 5,
        'account_decay_seconds' => 60,
        'ip_max_attempts' => 30,
        'ip_decay_seconds' => 600,
    ],

    /* Absolute lifetime of a signed-in session, in minutes (12 h), counted from the login and not extended by activity (App\Services\AbsoluteSession). SESSION_LIFETIME is the inactivity limit. */
    'absolute_session_minutes' => 720,

    /* Requests per minute of one signed-in person across the whole application (App\Providers\AppServiceProvider, limiter `authenticated`); routes with their own limit keep it. */
    'authenticated_per_minute' => 600,

    'remembered_access_minutes' => (int) env('AUTH_REMEMBERED_ACCESS_MINUTES', 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
