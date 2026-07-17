<?php

namespace App\Support;

/**
 * The four realtime signal names, ported 1:1 from the Node app's Socket.IO
 * event names (kot/rooms/orders/menu) — see phase2-nodejs-infrastructure memory.
 * All broadcast on the single public "hotel" channel; see App\Events\Hotel\RealtimeUpdate.
 */
class RealtimeEvent
{
    public const KOT = 'kot';

    public const ROOMS = 'rooms';

    public const ORDERS = 'orders';

    public const MENU = 'menu';
}
