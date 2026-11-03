<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default SMS Driver / Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "log", "alphanet"
    |
    */
    'default' => env('SMS_DEFAULT_DRIVER', 'log'),

    'providers' => [
        'alphanet' => [
            'api_key' => env('ALPHANET_SMS_API_KEY'),
            'sender_id' => env('ALPHANET_SMS_SENDER_ID'),
            'endpoint' => env('ALPHANET_SMS_ENDPOINT', 'https://api.sms.alphanet.net.bd/'),
        ],
        'log' => [
            'channel' => env('SMS_LOG_CHANNEL', 'stack'),
        ],
    ],
];
