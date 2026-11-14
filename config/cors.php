<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174,http://127.0.0.1:5173,https://9knv83ss-5173.asse.devtunnels.ms,https://9knv83ss-8000.asse.devtunnels.ms,https://local.amalebu.test,http://local.amalebu.test:88,https://amalebu.github.io'))
    ),

    'allowed_origins_patterns' => [
        '#^https://.*\.asse\.devtunnels\.ms$#',
        '#^https://.*\.devtunnels\.ms$#',
        '#^https://.*\.github\.io$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
