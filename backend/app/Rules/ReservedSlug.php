<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A slug that would shadow another host identity can never be a tenant slug:
 * the central subdomain, the apex ("www" is the conventional apex alias), and
 * every name infrastructure takes (cdn, api, mail, ...). This is relative-mode
 * aware — the central label is config, the rest are hostnames a tenant could
 * never own under any base.
 */
class ReservedSlug implements ValidationRule
{
    /** @var list<string> */
    private const INFRASTRUCTURE = [
        'central', 'www', 'api', 'app', 'mail', 'smtp', 'ftp', 'cdn', 'static',
        'assets', 'status', 'help', 'support', 'billing', 'dashboard',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = strtolower((string) $value);

        $reserved = array_merge(
            self::INFRASTRUCTURE,
            [strtolower((string) config('tenancy.central_subdomain'))],
        );

        if (in_array($slug, $reserved, true)) {
            $fail('That slug is reserved.');
        }
    }
}
