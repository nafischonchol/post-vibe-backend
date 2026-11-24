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
    ],


    'openrouter' => [
        'site_url' => env('APP_URL', 'http://localhost:3000'),
        'site_name' => env('APP_NAME', 'Post Vibe'),
    ],

    'yt_dlp' => [
        'binary_path' => env('YT_DLP_PATH', base_path('bin/yt-dlp')),
        'timeout' => (int) env('YT_DLP_TIMEOUT', 300),
        'max_filesize' => env('YT_DLP_MAX_FILESIZE', '150M'),
        'cookies_path' => env('YT_DLP_COOKIES_PATH', storage_path('app/cookies.txt')),
    ],

];
