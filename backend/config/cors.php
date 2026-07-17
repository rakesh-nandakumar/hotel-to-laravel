<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This API is consumed exclusively by the decoupled React SPA (and, later,
    | any other first-party client) over `api/*` plus Sanctum's CSRF-cookie
    | bootstrap route. Credentials must be allowed so the SPA's session cookie
    | is sent/received across origins.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', 'http://localhost:5173'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
