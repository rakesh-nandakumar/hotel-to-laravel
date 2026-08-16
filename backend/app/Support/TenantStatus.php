<?php

namespace App\Support;

/**
 * Fixed, non-business lifecycle states for a Tenant — a legitimate use of a
 * plain constants class rather than a lookup table (coding_principles.md §2).
 */
class TenantStatus
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';
}
