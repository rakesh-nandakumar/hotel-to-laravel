<?php

namespace App\Support\Lookups;

class ApartmentUnitStatus
{
    public const AVAILABLE = 'available';

    public const OCCUPIED = 'occupied';

    /** Vacated, needs turnover cleaning before it can be checked into/leased again — see Apartments housekeeping (M5). */
    public const DIRTY = 'dirty';

    /** Held by an active booking/lease turnover or a pending sale — not sellable/bookable right now. */
    public const RESERVED = 'reserved';

    public const MAINTENANCE = 'maintenance';

    /** Deliberately withheld from any booking/lease/sale flow (owner-blocked, renovation, etc.). */
    public const BLOCKED = 'blocked';

    public const SOLD = 'sold';

    /** Off the rental/sale market entirely but still tracked in inventory. */
    public const OFF_MARKET = 'off_market';
}
