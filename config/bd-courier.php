<?php

use Bonik\Courier\Services\CarryBeeService;
use Bonik\Courier\Services\EliteMartService;
use Bonik\Courier\Services\PathaoService;
use Bonik\Courier\Services\RedxService;
use Bonik\Courier\Services\SteadfastService;

return [
    'request' => [
        'timeout' => env('COURIER_FRAUD_CHECKER_REQUEST_TIMEOUT', 10),
        'retry_attempts' => env('COURIER_FRAUD_CHECKER_REQUEST_RETRY', 2),
        'retry_sleep' => env('COURIER_FRAUD_CHECKER_REQUEST_RETRY_SLEEP', 200),
    ],

    'cache' => [
        'token_ttl_minutes' => env('COURIER_FRAUD_CHECKER_TOKEN_TTL_MINUTES', 50),
        'results_enabled' => env('COURIER_FRAUD_CHECKER_RESULTS_CACHE', true),
        'results_ttl_minutes' => env('COURIER_FRAUD_CHECKER_RESULTS_TTL_MINUTES', 15),
    ],

    'webhook' => [
        'enabled' => env('COURIER_WEBHOOK_ENABLED', true),
        'prefix' => env('COURIER_WEBHOOK_PREFIX', 'api/courier/webhook'),
        'middleware' => ['api'],
    ],

    'credential_table' => env('COURIER_FRAUD_CHECKER_CREDENTIAL_TABLE', 'courier_fraud_checker_credentials'),
    'credential_provider_column' => env('COURIER_FRAUD_CHECKER_PROVIDER_COLUMN', 'courier'),
    'credential_value_column' => env('COURIER_FRAUD_CHECKER_CREDENTIAL_COLUMN', 'credential'),
    'default_rate_limit_minutes' => env('COURIER_FRAUD_CHECKER_RATE_LIMIT_MINUTES', 15),

    'rate_limits' => [
        'steadfast' => env('COURIER_FRAUD_CHECKER_STEADFAST_RATE_LIMIT', 15),
        'pathao' => env('COURIER_FRAUD_CHECKER_PATHAO_RATE_LIMIT', 15),
        'redx' => env('COURIER_FRAUD_CHECKER_REDX_RATE_LIMIT', 15),
        'carrybee' => env('COURIER_FRAUD_CHECKER_CARRYBEE_RATE_LIMIT', 15),
    ],

    'steadfast' => [
        'enable' => env('COURIER_FRAUD_CHECKER_STEADFAST_ENABLE', false),
    ],

    'pathao' => [
        'enable' => env('COURIER_FRAUD_CHECKER_PATHAO_ENABLE', false),
        'sandbox' => env('PATHAO_SANDBOX', false),
    ],

    'redx' => [
        'enable' => env('COURIER_FRAUD_CHECKER_REDX_ENABLE', false),
    ],

    'carrybee' => [
        'enable' => env('COURIER_FRAUD_CHECKER_CARRYBEE_ENABLE', false),
    ],

    'elitemart' => [
        'enable' => env('COURIER_FRAUD_CHECKER_ELITEMART_ENABLE', true),
    ],

    'couriers' => [
        'steadfast' => SteadfastService::class,
        'pathao' => PathaoService::class,
        'redx' => RedxService::class,
        'carrybee' => CarryBeeService::class,
    ],

    'fraud_checkers' => [
        'elitemart' => EliteMartService::class,
    ],
];
