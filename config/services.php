<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /* Meta app that owns the WhatsApp numbers: the webhook is only served when both values are set (see WhatsAppWebhookController). */
    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        // Messenger (Facebook Login): the App ID of the Meta app Zonno publishes. Its secret is `app_secret` above.
        'app_id' => env('META_APP_ID'),
        // Instagram API with Instagram Login: the Instagram app ID and secret shown in the app dashboard (Instagram > API setup). Separate from the Facebook ones.
        'instagram_app_id' => env('META_INSTAGRAM_APP_ID'),
        'instagram_app_secret' => env('META_INSTAGRAM_APP_SECRET'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
