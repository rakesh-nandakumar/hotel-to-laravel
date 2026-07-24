<?php

namespace App\Support\Lookups;

class OrderStatus
{
    public const OPEN = 'open';

    public const PARKED = 'parked';

    public const SETTLED = 'settled';

    public const CHARGED_TO_ROOM = 'charged_to_room';

    public const VOID = 'void';

    /** Bill was split into child orders (see Order::childOrders()) — no longer independently payable. */
    public const SPLIT = 'split';

    /** Folded into another order via OrderService::mergeOrders() — see Order::parentOrder(). */
    public const MERGED = 'merged';
}
