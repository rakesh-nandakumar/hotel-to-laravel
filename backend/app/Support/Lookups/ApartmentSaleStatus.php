<?php

namespace App\Support\Lookups;

class ApartmentSaleStatus
{
    /** A lead — expressed interest, no exclusivity, unit stays fully available. */
    public const INQUIRY = 'inquiry';

    /** Holding deposit paid — unit locked from rental/other buyers until reserved_until. */
    public const RESERVED = 'reserved';

    public const AGREEMENT_SIGNED = 'agreement_signed';

    /** Paid in full and handed over — unit permanently marked SOLD. */
    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';
}
