<?php

namespace Database\Seeders;

use App\Models\Lookup;
use App\Support\Lookups\BookingChannel;
use App\Support\Lookups\CheckKind;
use App\Support\Lookups\DiningMode;
use App\Support\Lookups\DurationType;
use App\Support\Lookups\FolioStatus;
use App\Support\Lookups\FolioType;
use App\Support\Lookups\KotStatus;
use App\Support\Lookups\LineSource;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\MaintenanceStatus;
use App\Support\Lookups\NotificationChannel;
use App\Support\Lookups\NotificationStatus;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use App\Support\Lookups\PayrollStatus;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\Lookups\VenueBookingStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds every lookup/reference list the hotel domain needs (replacing what
 * would otherwise be DB enums — coding_principles.md §2). Idempotent:
 * safe to re-run, existing rows are updated in place rather than duplicated.
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedType(LookupType::RESERVATION_STATUS, [
            [ReservationStatus::PENDING, 'Pending', 'gray'],
            [ReservationStatus::CONFIRMED, 'Confirmed', 'blue'],
            [ReservationStatus::CHECKED_IN, 'Checked In', 'green'],
            [ReservationStatus::CHECKED_OUT, 'Checked Out', 'slate'],
            [ReservationStatus::CANCELLED, 'Cancelled', 'red'],
            [ReservationStatus::NO_SHOW, 'No Show', 'orange'],
        ]);

        $this->seedType(LookupType::ROOM_STATUS, [
            [RoomStatus::AVAILABLE, 'Available', 'green'],
            [RoomStatus::OCCUPIED, 'Occupied', 'blue'],
            [RoomStatus::DIRTY, 'Dirty', 'orange'],
            [RoomStatus::MAINTENANCE, 'Maintenance', 'red'],
        ]);

        $this->seedType(LookupType::ORDER_STATUS, [
            [OrderStatus::OPEN, 'Open', 'blue'],
            [OrderStatus::PARKED, 'Parked', 'orange'],
            [OrderStatus::SETTLED, 'Settled', 'green'],
            [OrderStatus::CHARGED_TO_ROOM, 'Charged to Room', 'purple'],
            [OrderStatus::VOID, 'Void', 'red'],
        ]);

        $this->seedType(LookupType::KOT_STATUS, [
            [KotStatus::NEW, 'New', 'gray'],
            [KotStatus::PREPARING, 'Preparing', 'orange'],
            [KotStatus::READY, 'Ready', 'green'],
            [KotStatus::SERVED, 'Served', 'slate'],
        ]);

        $this->seedType(LookupType::FOLIO_STATUS, [
            [FolioStatus::OPEN, 'Open', 'blue'],
            [FolioStatus::SETTLED, 'Settled', 'green'],
            [FolioStatus::VOID, 'Void', 'red'],
        ]);

        $this->seedType(LookupType::FOLIO_TYPE, [
            [FolioType::GUEST, 'Guest', 'blue'],
            [FolioType::VENUE, 'Venue', 'purple'],
        ]);

        $this->seedType(LookupType::PAYROLL_STATUS, [
            [PayrollStatus::DRAFT, 'Draft', 'gray'],
            [PayrollStatus::FINALIZED, 'Finalized', 'green'],
        ]);

        $this->seedType(LookupType::VENUE_BOOKING_STATUS, [
            [VenueBookingStatus::INQUIRY, 'Inquiry', 'gray'],
            [VenueBookingStatus::CONFIRMED, 'Confirmed', 'blue'],
            [VenueBookingStatus::COMPLETED, 'Completed', 'green'],
            [VenueBookingStatus::CANCELLED, 'Cancelled', 'red'],
        ]);

        $this->seedType(LookupType::MAINTENANCE_STATUS, [
            [MaintenanceStatus::OPEN, 'Open', 'red'],
            [MaintenanceStatus::IN_PROGRESS, 'In Progress', 'orange'],
            [MaintenanceStatus::RESOLVED, 'Resolved', 'green'],
        ]);

        $this->seedType(LookupType::TASK_STATUS, [
            [TaskStatus::PENDING, 'Pending', 'gray'],
            [TaskStatus::IN_PROGRESS, 'In Progress', 'orange'],
            [TaskStatus::DONE, 'Done', 'green'],
        ]);

        $this->seedType(LookupType::NOTIFICATION_STATUS, [
            [NotificationStatus::QUEUED, 'Queued', 'gray'],
            [NotificationStatus::SENT, 'Sent', 'green'],
            [NotificationStatus::FAILED, 'Failed', 'red'],
        ]);

        $this->seedType(LookupType::NOTIFICATION_CHANNEL, [
            [NotificationChannel::EMAIL, 'Email', 'blue'],
            [NotificationChannel::WHATSAPP, 'WhatsApp', 'green'],
            [NotificationChannel::SMS, 'SMS', 'purple'],
        ]);

        $this->seedType(LookupType::PAYMENT_METHOD, [
            [PaymentMethod::CASH, 'Cash', 'green'],
            [PaymentMethod::CARD, 'Card', 'blue'],
            [PaymentMethod::LANKAQR, 'LankaQR', 'purple'],
            [PaymentMethod::BANK_TRANSFER, 'Bank Transfer', 'slate'],
            [PaymentMethod::CORPORATE_CREDIT, 'Corporate Credit', 'orange'],
            [PaymentMethod::LOYALTY_POINTS, 'Loyalty Points', 'pink'],
        ]);

        $this->seedType(LookupType::PAYMENT_KIND, [
            [PaymentKind::PAYMENT, 'Payment', 'green'],
            [PaymentKind::DEPOSIT, 'Deposit', 'blue'],
            [PaymentKind::REFUND, 'Refund', 'red'],
        ]);

        $this->seedType(LookupType::LINE_SOURCE, [
            [LineSource::ROOM, 'Room', 'blue'],
            [LineSource::PACKAGE, 'Package', 'blue'],
            [LineSource::RESTAURANT, 'Restaurant', 'orange'],
            [LineSource::MINIBAR, 'Minibar', 'orange'],
            [LineSource::VENUE, 'Venue', 'purple'],
            [LineSource::LAUNDRY, 'Laundry', 'slate'],
            [LineSource::SURCHARGE, 'Surcharge', 'gray'],
            [LineSource::SERVICE_CHARGE, 'Service Charge', 'gray'],
            [LineSource::VAT, 'VAT', 'gray'],
            [LineSource::DISCOUNT, 'Discount', 'green'],
            [LineSource::DAMAGE, 'Damage', 'red'],
            [LineSource::ADJUSTMENT, 'Adjustment', 'gray'],
            [LineSource::LOYALTY_REDEMPTION, 'Loyalty Redemption', 'pink'],
        ]);

        $this->seedType(LookupType::BOOKING_CHANNEL, [
            [BookingChannel::BOOKING_COM, 'Booking.com', 'blue'],
            [BookingChannel::WEBSITE, 'Website', 'purple'],
            [BookingChannel::PHONE, 'Phone', 'orange'],
            [BookingChannel::WALKIN, 'Walk-in', 'slate'],
        ]);

        $this->seedType(LookupType::DURATION_TYPE, [
            [DurationType::HOURLY, 'Hourly', 'blue'],
            [DurationType::HALF_DAY, 'Half Day', 'orange'],
            [DurationType::FULL_DAY, 'Full Day', 'green'],
        ]);

        $this->seedType(LookupType::CHECK_KIND, [
            [CheckKind::CHECK_IN, 'Check In', 'green'],
            [CheckKind::CHECK_OUT, 'Check Out', 'slate'],
        ]);

        $this->seedType(LookupType::DINING_MODE, [
            [DiningMode::DINE_IN, 'Dine In', 'blue'],
            [DiningMode::TAKEAWAY, 'Takeaway', 'orange'],
        ]);

        $this->seedType(LookupType::ORDER_TYPE, [
            [OrderType::ROOM_GUEST, 'Room Guest', 'blue'],
            [OrderType::WALKIN, 'Walk-in', 'slate'],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows  [code, name, color]
     */
    private function seedType(string $type, array $rows): void
    {
        foreach ($rows as $sortOrder => [$code, $name, $color]) {
            Lookup::updateOrCreate(
                ['type' => $type, 'code' => $code],
                ['name' => $name, 'color' => $color, 'sort_order' => $sortOrder, 'is_active' => true],
            );
        }
    }
}
