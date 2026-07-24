<?php

namespace App\Support\Lookups;

/**
 * A unit's primary commercial purpose. Gates which apartment sub-module may
 * book it — the apartment availability service (booking/lease engine, M2)
 * rejects any rental against a unit whose listing type isn't RENTAL, and the
 * sales pipeline (M4) requires SALE.
 */
class ApartmentListingType
{
    public const RENTAL = 'rental';

    public const SALE = 'sale';
}
