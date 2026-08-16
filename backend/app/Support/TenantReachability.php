<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Whether a tenant may be served at all (IdentifyTenant and
 * HostContextController must agree exactly — the SPA's boot gate and the
 * request pipeline resolve the same way, or the app would render a shell
 * for a host the API then 404s). Anything that isn't unambiguously
 * ACTIVE or a not-yet-expired TRIAL is unreachable — suspended and expired
 * tenants are indistinguishable from hosts that don't exist.
 */
class TenantReachability
{
    public static function check(Tenant $tenant): bool
    {
        return match ($tenant->status) {
            TenantStatus::ACTIVE => true,
            TenantStatus::TRIAL => $tenant->trial_ends_at === null
                || Carbon::parse($tenant->trial_ends_at)->isFuture(),
            default => false,
        };
    }
}
