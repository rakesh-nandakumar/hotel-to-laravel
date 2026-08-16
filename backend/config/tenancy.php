<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base domain (relative mode)
    |--------------------------------------------------------------------------
    |
    | Tenant resolution is RELATIVE: the first DNS label of the Host header is
    | the identity (a tenant slug, or the central subdomain) and everything
    | after it is the base. No domain literal is ever baked in — the same
    | code serves {slug}.vellixglobal.com and {slug}.any-other-domain.com.
    | A bare base (e.g. "vellixglobal.com", "localhost") is central context.
    |
    | Setting TENANCY_BASE_DOMAIN pins strict mode: a request whose base does
    | not match it exactly is rejected (unknown host). Unset/null = relative.
    |
    */

    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Central subdomain
    |--------------------------------------------------------------------------
    |
    | The first DNS label that maps to "master control" (tenant administration)
    | instead of a tenant: admin.vellixglobal.com, admin.hms.vellixglobal.com,
    | admin.localhost — all depths resolve to central.
    |
    */

    'central_subdomain' => env('TENANCY_CENTRAL_SUBDOMAIN', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Minimum base labels
    |--------------------------------------------------------------------------
    |
    | The smallest number of labels a base may have. A base below this floor
    | is the apex (central). The default of 2 assumes a single-label TLD
    | ("vellixglobal.com" → base "com"); deployments on multipart TLDs such as
    | .co.uk must set this to 3 so "example.co.uk" resolves as a tenant
    | subdomain, not the apex. *.localhost always gets a floor of 1 so local
    | development subdomains resolve.
    |
    */

    'min_base_labels' => env('TENANCY_MIN_BASE_LABELS', 2),

    /*
    |--------------------------------------------------------------------------
    | Local/dev convenience (opt-in)
    |--------------------------------------------------------------------------
    |
    | Local development and the automated test suite don't always run under
    | real subdomains (php artisan serve on 127.0.0.1, Vite on localhost).
    | With TENANCY_DEV_FALLBACK=true, requests whose host is genuinely local
    | (localhost, *.localhost, 127.0.0.1, [::1]) and still unresolved may name
    | a tenant via the X-Tenant-Slug header or `tenant` query parameter
    | (sticky for the session). Default false — never enabled in production.
    |
    */

    'dev_fallback' => env('TENANCY_DEV_FALLBACK', false),

];
