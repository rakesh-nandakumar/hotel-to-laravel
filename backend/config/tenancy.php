<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base domain
    |--------------------------------------------------------------------------
    |
    | Every tenant is reached at {slug}.{base_domain} (wildcard DNS/SSL is
    | assumed to be provisioned for this domain). The bare base domain, and
    | the reserved central subdomain below, are the "master control" side —
    | IdentifyTenant treats both as central context and resolves no tenant.
    |
    */

    'base_domain' => env('TENANCY_BASE_DOMAIN', 'vellixglobal.com'),

    'central_subdomain' => env('TENANCY_CENTRAL_SUBDOMAIN', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Local/dev convenience
    |--------------------------------------------------------------------------
    |
    | Local development and the automated test suite don't run under real
    | subdomains (php artisan serve on 127.0.0.1, Vite on localhost). Rather
    | than fail closed for the entire local workflow, requests whose host
    | doesn't match the pattern above fall back to an explicit
    | X-Tenant-Slug header/`tenant` query param when present, else the single
    | tenant in the database when there is exactly one — mirroring
    | CurrentContext's existing "one branch → use it implicitly" convenience.
    | Never used outside local/testing (see IdentifyTenant).
    |
    */

    'dev_fallback' => env('APP_ENV') !== 'production',

];
