<?php

/*
|--------------------------------------------------------------------------
| CORS (module Enquêtes, tâche B-02)
|--------------------------------------------------------------------------
|
| Origines autorisées avec credentials : FRONTEND_URL + CORS_EXTRA_ORIGINS (liste CSV).
| Hors production, tout http://localhost:<port> est accepté (Next.js, Flutter web, outils).
| api/public/* est ouvert à toutes les origines (sans credentials) par App\Http\Middleware\PublicCors.
|
*/

$origins = array_merge(
    [env('FRONTEND_URL', 'http://localhost:3000')],
    explode(',', (string) env('CORS_EXTRA_ORIGINS', '')),
);

$origins = array_values(array_unique(array_filter(array_map(
    static fn ($origin) => rtrim(trim((string) $origin), '/'),
    $origins,
))));

return [

    'paths' => ['api/*', 'media/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'production'
        ? []
        : ['#^http://localhost:\d+$#', '#^http://127\.0\.0\.1:\d+$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['ETag', 'X-Server-Time', 'Retry-After', 'Location'],

    'max_age' => 600,

    'supports_credentials' => true,

];
