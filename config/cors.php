<?php
// Explicit first-party Flutter web origins. Set the production origin in .env.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FLUTTER_WEB_ORIGINS', 'http://localhost:8085,http://127.0.0.1:8085')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
