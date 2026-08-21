<?php

namespace App\Support\Lookups;

class StockMovementType
{
    public const GRN_RECEIPT = 'grn_receipt';

    public const ADJUSTMENT = 'adjustment';

    public const SALE = 'sale';

    public const SALE_REVERSAL = 'sale_reversal';

    public const WRITE_OFF = 'write_off';
}
