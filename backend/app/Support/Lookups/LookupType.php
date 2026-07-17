<?php

namespace App\Support\Lookups;

/**
 * Every `type` discriminator seeded into the `lookups` table. Add a new
 * constant here (and a matching Seeder entry + code-constants class) when a
 * module introduces a new classification/status list — never a DB enum.
 */
class LookupType
{
    public const RESERVATION_STATUS = 'reservation_status';

    public const ROOM_STATUS = 'room_status';

    public const ORDER_STATUS = 'order_status';

    public const KOT_STATUS = 'kot_status';

    public const FOLIO_STATUS = 'folio_status';

    public const FOLIO_TYPE = 'folio_type';

    public const PAYROLL_STATUS = 'payroll_status';

    public const VENUE_BOOKING_STATUS = 'venue_booking_status';

    public const MAINTENANCE_STATUS = 'maintenance_status';

    public const TASK_STATUS = 'task_status';

    public const NOTIFICATION_STATUS = 'notification_status';

    public const NOTIFICATION_CHANNEL = 'notification_channel';

    public const PAYMENT_METHOD = 'payment_method';

    public const PAYMENT_KIND = 'payment_kind';

    public const LINE_SOURCE = 'line_source';

    public const BOOKING_CHANNEL = 'booking_channel';

    public const DURATION_TYPE = 'duration_type';

    public const CHECK_KIND = 'check_kind';

    public const DINING_MODE = 'dining_mode';

    public const ORDER_TYPE = 'order_type';
}
