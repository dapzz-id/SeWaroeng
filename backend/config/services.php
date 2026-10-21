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

    'mock_payment' => [
        'webhook_secret' => env('MOCK_PAYMENT_WEBHOOK_SECRET', 'mock-webhook-secret'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
        'merchant_id' => env('MIDTRANS_MERCHANT_ID', ''),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'base_url' => env('MIDTRANS_BASE_URL', null),
    ],

    'duitku' => [
        'merchant_code' => env('DUITKU_MERCHANT_CODE', ''),
        'api_key' => env('DUITKU_API_KEY', ''),
        'is_production' => (bool) env('DUITKU_IS_PRODUCTION', false),
    ],

    'whatsapp' => [
        'support_contact' => env('SEWAROENG_SUPPORT_CONTACT', null),
        'meta' => [
            'graph_version' => env('META_WHATSAPP_GRAPH_VERSION', env('WHATSAPP_GRAPH_VERSION', 'v21.0')),
            'app_secret' => env('META_WHATSAPP_APP_SECRET', env('WHATSAPP_WEBHOOK_SECRET', env('WHATSAPP_APP_SECRET', null))),
            'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN', null)),
            'supported_versions' => ['v21.0', 'v22.0', 'v25.0'],
        ],
    ],

];
