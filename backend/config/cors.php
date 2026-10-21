<?php

$appEnv = env('APP_ENV', 'production');
$configuredOrigins = env('CORS_ALLOWED_ORIGINS');

// Parse explicit origins from environment variable
$allowedOrigins = [];
if (! empty($configuredOrigins)) {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', (string) $configuredOrigins))));
}

// In production and testing environments, permit the planned frontend origin
if ($appEnv === 'production' || $appEnv === 'testing') {
    $prodOrigin = 'https://sewaroeng.neuracakrawira.asia';
    if (! in_array($prodOrigin, $allowedOrigins, true)) {
        $allowedOrigins[] = $prodOrigin;
    }
}

// In local and testing environments, permit local development origins
if ($appEnv === 'local' || $appEnv === 'testing') {
    $localOrigins = [
        'http://localhost:3000',
        'http://localhost:8000',
        'http://localhost:8080',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:8000',
        'http://127.0.0.1:8080',
    ];
    foreach ($localOrigins as $loc) {
        if (! in_array($loc, $allowedOrigins, true)) {
            $allowedOrigins[] = $loc;
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => ($appEnv === 'local' || $appEnv === 'testing')
        ? [
            '#^http://localhost:\d+$#',
            '#^http://127\.0\.0\.1:\d+$#',
        ]
        : [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Tenant-ID',
        'X-Request-ID',
        'X-Requested-With',
    ],

    'exposed_headers' => [
        'X-Request-ID',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,

];
