<?php

namespace App\Support;

/** Integer LKR cents → a "1,200.00"-style display string, used across every PDF document. */
class Money
{
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
