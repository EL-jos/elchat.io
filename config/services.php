<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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
        'client_id'       => env('SLACK_CLIENT_ID'),
        'client_secret'   => env('SLACK_CLIENT_SECRET'),
        'redirect'        => env('SLACK_REDIRECT_URI'),
        'signing_secret'  => env('SLACK_SIGNING_SECRET'), // ✅ requis pour SlackWebhookSecurityService
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'youtube' => [
        'videos_per_sync'  => env('YOUTUBE_VIDEOS_PER_SYNC', 25),
        'max_comment_pages'=> env('YOUTUBE_MAX_COMMENT_PAGES', 20),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'webhook_verify_token' => env(
            'FACEBOOK_VERIFY_TOKEN'
        ),
        'app_secret' => env(
            'FACEBOOK_APP_SECRET'
        ),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v25.0'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
    ],

    'telegram' => [
        'bot_api' => env('TELEGRAM_BOT_API', 'https://api.telegram.org'),
    ]
];
