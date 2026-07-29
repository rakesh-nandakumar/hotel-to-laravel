<?php

namespace App\Support\Lookups;

/**
 * Every till_movements.type_id code. IN types store a positive amount, OUT
 * types a negative one — see TillService::recordMovement().
 */
class TillMovementType
{
    public const OPENING_BALANCE = 'opening_balance';

    public const CASH_IN = 'cash_in';

    public const CASH_OUT = 'cash_out';

    public const REFUND = 'refund';

    public const EXPENSE = 'expense';

    public const TRANSFER = 'transfer';

    public const CLOSING_ADJUSTMENT = 'closing_adjustment';

    /** @var list<string> */
    public const IN_TYPES = [self::OPENING_BALANCE, self::CASH_IN];

    /** @var list<string> */
    public const OUT_TYPES = [self::CASH_OUT, self::REFUND, self::EXPENSE, self::TRANSFER];

    /** Types where a reason is mandatory — money leaving the till must always be explained. */
    public const REASON_REQUIRED = [self::CASH_OUT, self::REFUND, self::EXPENSE, self::TRANSFER];
}
