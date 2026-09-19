<?php

namespace App\Support;

use App\Models\Apartment\Payment as ApartmentPayment;
use App\Models\Hotel\Payment as HotelPayment;
use App\Support\Lookups\FolioType;

/**
 * How a cash movement's originating Payment is grouped on the till close-out
 * summary — "Room bookings", "Restaurant orders", and so on.
 *
 * These are *derived* report groupings, never stored: no column anywhere holds
 * one of these codes, so they stay code constants rather than a lookup table
 * (coding_principles.md §2 governs stored, configurable business values).
 *
 * Cash is attributed to what the *payment* was taken against, not to the
 * individual charge lines that payment settled. A restaurant charge posted to
 * a room folio and paid at checkout therefore counts as room-booking cash —
 * that is the transaction the cashier actually took the money for, and it is
 * the only attribution that reconciles against the physical drawer.
 */
class TillActivityCategory
{
    public const ROOM_BOOKINGS = 'room_bookings';

    public const RESTAURANT = 'restaurant';

    public const VENUE_BOOKINGS = 'venue_bookings';

    public const CORPORATE = 'corporate';

    public const APARTMENT_STAYS = 'apartment_stays';

    public const APARTMENT_RENT = 'apartment_rent';

    public const APARTMENT_SALES = 'apartment_sales';

    public const OTHER = 'other';

    /**
     * Label per category, in the order the close-out summary lists them.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::ROOM_BOOKINGS => 'Room bookings',
        self::RESTAURANT => 'Restaurant orders',
        self::VENUE_BOOKINGS => 'Venue bookings',
        self::CORPORATE => 'Corporate settlements',
        self::APARTMENT_STAYS => 'Apartment stays',
        self::APARTMENT_RENT => 'Apartment rent',
        self::APARTMENT_SALES => 'Apartment sales',
        self::OTHER => 'Other collections',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? self::LABELS[self::OTHER];
    }

    /** Requires `folio.type` to be loaded — an unloaded folio falls through to ROOM_BOOKINGS, not VENUE_BOOKINGS. */
    public static function forHotelPayment(HotelPayment $payment): string
    {
        return match (true) {
            $payment->order_id !== null => self::RESTAURANT,
            $payment->folio?->type?->code === FolioType::VENUE => self::VENUE_BOOKINGS,
            $payment->folio_id !== null => self::ROOM_BOOKINGS,
            $payment->corporate_account_id !== null => self::CORPORATE,
            default => self::OTHER,
        };
    }

    /** Requires `ledger` to be loaded. A ledger carries exactly one of sale/lease/booking. */
    public static function forApartmentPayment(ApartmentPayment $payment): string
    {
        return match (true) {
            $payment->ledger?->sale_id !== null => self::APARTMENT_SALES,
            $payment->ledger?->lease_id !== null => self::APARTMENT_RENT,
            $payment->ledger?->booking_id !== null => self::APARTMENT_STAYS,
            default => self::OTHER,
        };
    }
}
