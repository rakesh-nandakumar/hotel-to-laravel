<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => (function (): array {
        $explicit = env('SANCTUM_STATEFUL_DOMAINS');

        $domains = $explicit !== null
            ? explode(',', $explicit)
            : [
                'localhost',
                'localhost:3000',
                '127.0.0.1',
                '127.0.0.1:8000',
                '::1',
                Sanctum::currentApplicationUrlWithPort(),
            ];

        // Path-prefix tenancy: the SPA and the API share ONE host, and the
        // tenant identity rides the X-Tenant-Slug header, so the bare app host
        // covers everything. The wildcard entries are transitional — the old
        // {slug}.{base} page loads of the cutover window still need a stateful
        // session (otherwise their first API call 401s); they only emit while
        // TENANCY_BASE_DOMAIN is still set. Sanctum only attaches the session
        // store (so $request->session() works) for requests whose Origin/Referer
        // matches a `stateful` domain; a request left stateless that then reads
        // the session throws "Session store not set on request".
        $hosts = array_filter([
            parse_url((string) env('APP_URL', ''), PHP_URL_HOST),
            trim((string) env('TENANCY_BASE_DOMAIN', '')),
        ]);

        foreach (array_unique($hosts) as $host) {
            $domains[] = "*.{$host}";
            $domains[] = "*.{$host}:*";
        }

        return array_values(array_filter(array_unique($domains)));
    })(),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
