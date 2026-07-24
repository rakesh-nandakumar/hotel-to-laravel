<?php

namespace App\Support\Lookups;

/** Apartment ledger charge-line category — the Apartments-module equivalent of Hotel's LineSource. */
class ApartmentLineSource
{
    public const RENT = 'rent';

    public const CLEANING_FEE = 'cleaning_fee';

    public const EXTRA_GUEST_FEE = 'extra_guest_fee';

    public const UTILITY = 'utility';

    public const SURCHARGE = 'surcharge';

    public const SERVICE_CHARGE = 'service_charge';

    public const VAT = 'vat';

    public const DISCOUNT = 'discount';

    public const DAMAGE = 'damage';

    public const ADJUSTMENT = 'adjustment';

    public const INSTALLMENT = 'installment';
}
