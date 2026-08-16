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

    // Must be the SPA's exact origin(s), never '*': the browser rejects a
    // wildcard Access-Control-Allow-Origin on credentialed (cookie) requests.
    // Comma-separate FRONTEND_URL to allow more than one (e.g. prod + preview).
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')),
    ))),

    // Every tenant is its own origin ({slug}.{base}), so they can't be
    // enumerated in allowed_origins ahead of time — one pattern covers the
    // whole wildcard-DNS space instead. Anchored, with the base domain quoted,
    // so it matches exactly one label of subdomain on the configured domain
    // and nothing else (notably not "vellixglobal.com.attacker.test").
    //
    // The base is RELATIVE by default (see tenancy.php): this app normally
    // serves SPA + API from the same host, where CORS never applies at all.
    // The pattern is therefore only emitted when TENANCY_BASE_DOMAIN is
    // explicitly pinned to a fixed domain (split-host setups).
    //
    // Reads env() rather than config('tenancy.base_domain') on purpose: config
    // files are loaded alphabetically, so tenancy.php isn't in the repository
    // yet when this one is evaluated. Keep the default in sync with it.
    'allowed_origins_patterns' => array_values(array_filter([
        ($base = trim((string) env('TENANCY_BASE_DOMAIN', '')))
            ? '#^https?://[a-z0-9-]+\.'.preg_quote($base, '#').'$#i'
            : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
