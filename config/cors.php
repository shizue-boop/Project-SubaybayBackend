<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('FRONTEND_ORIGINS', 'http://localhost:5173'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    // Cache successful preflight responses so cross-origin API calls do not
    // spend an extra network round trip on every request.
    'max_age' => 86400,
    'supports_credentials' => false,
];
