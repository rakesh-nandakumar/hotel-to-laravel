<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central prefix
    |--------------------------------------------------------------------------
    |
    | The URL path prefix that maps to "master control" (tenant administration)
    | instead of a tenant: hms.com/admin/... is central, hms.com/wasana/... is
    | tenant "wasana". The same string is what a tenant-slot identity check
    | refuses (ReservedSlug), and a header-sourced slug equal to it fails
    | closed rather than granting central — master control is only ever
    | reachable on a central path, never through a tenant hint.
    |
    */

    'central_prefix' => env('TENANCY_CENTRAL_PREFIX', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Host fallback (transitional)
    |--------------------------------------------------------------------------
    |
    | Dual-mode window: IdentifyTenant and HostContextController resolve the
    | tenant from the X-Tenant-Slug header (or `tenant` query parameter) and
    | fall back to the Host header so the old {slug}.{base}/admin.{base} URL
    | style keeps working while printed QR codes and bookmarks age out.
    |
    | base_domain (TENANCY_BASE_DOMAIN) pins strict mode: a request whose base
    | does not match it exactly is rejected (unknown host). Unset/null =
    | relative mode, where the first DNS label is the identity. min_base_labels
    | is the smallest number of labels a base may have below which a host is
    | the apex (central). Both are consulted ONLY by the host fallback and are
    | removed together with it at cutover.
    |
    */

    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    'min_base_labels' => env('TENANCY_MIN_BASE_LABELS', 2),

];
