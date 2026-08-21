<?php

namespace Database\Seeders;

use App\Models\Lookup;
use App\Support\Lookups\ApartmentBookingChannel;
use App\Support\Lookups\ApartmentBookingStatus;
use App\Support\Lookups\ApartmentLeaseStatus;
use App\Support\Lookups\ApartmentLedgerStatus;
use App\Support\Lookups\ApartmentLineSource;
use App\Support\Lookups\ApartmentListingType;
use App\Support\Lookups\ApartmentSaleStatus;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\ApartmentUtilityType;
use App\Support\Lookups\BookingChannel;
use App\Support\Lookups\CheckKind;
use App\Support\Lookups\DeliveryStatus;
use App\Support\Lookups\DiningMode;
use App\Support\Lookups\DurationType;
use App\Support\Lookups\FolioStatus;
use App\Support\Lookups\FolioType;
use App\Support\Lookups\GrnStatus;
use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\KitchenStation;
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
use App\Support\Lookups\StockMovementType;
use App\Support\Lookups\TableStatus;
use App\Support\Lookups\TaskStatus;
use App\Support\Lookups\TillMovementType;
use App\Support\Lookups\TillSessionStatus;
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
            [OrderStatus::SPLIT, 'Split', 'slate'],
            [OrderStatus::MERGED, 'Merged', 'slate'],
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
            [LineSource::CANCELLATION_FEE, 'Cancellation Fee', 'red'],
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
            [OrderType::DELIVERY, 'Delivery', 'purple'],
        ]);

        $this->seedType(LookupType::TABLE_STATUS, [
            [TableStatus::FREE, 'Free', 'green'],
            [TableStatus::OCCUPIED, 'Occupied', 'blue'],
            [TableStatus::RESERVED, 'Reserved', 'orange'],
            [TableStatus::CLEANING, 'Cleaning', 'gray'],
        ]);

        $this->seedType(LookupType::DELIVERY_STATUS, [
            [DeliveryStatus::PENDING, 'Pending', 'gray'],
            [DeliveryStatus::OUT_FOR_DELIVERY, 'Out for Delivery', 'blue'],
            [DeliveryStatus::DELIVERED, 'Delivered', 'green'],
            [DeliveryStatus::FAILED, 'Failed', 'red'],
        ]);

        $this->seedType(LookupType::KITCHEN_STATION, [
            [KitchenStation::KITCHEN, 'Kitchen', 'orange'],
            [KitchenStation::BAR, 'Bar', 'purple'],
            [KitchenStation::DESSERT, 'Dessert', 'pink'],
        ]);

        $this->seedType(LookupType::APARTMENT_LISTING_TYPE, [
            [ApartmentListingType::RENTAL, 'Rental', 'blue'],
            [ApartmentListingType::SALE, 'For Sale', 'purple'],
        ]);

        $this->seedType(LookupType::APARTMENT_UNIT_STATUS, [
            [ApartmentUnitStatus::AVAILABLE, 'Available', 'green'],
            [ApartmentUnitStatus::OCCUPIED, 'Occupied', 'blue'],
            [ApartmentUnitStatus::DIRTY, 'Dirty', 'orange'],
            [ApartmentUnitStatus::RESERVED, 'Reserved', 'orange'],
            [ApartmentUnitStatus::MAINTENANCE, 'Maintenance', 'red'],
            [ApartmentUnitStatus::BLOCKED, 'Blocked', 'gray'],
            [ApartmentUnitStatus::SOLD, 'Sold', 'slate'],
            [ApartmentUnitStatus::OFF_MARKET, 'Off Market', 'gray'],
        ]);

        $this->seedType(LookupType::APARTMENT_LEDGER_STATUS, [
            [ApartmentLedgerStatus::OPEN, 'Open', 'blue'],
            [ApartmentLedgerStatus::SETTLED, 'Settled', 'green'],
            [ApartmentLedgerStatus::VOID, 'Void', 'red'],
        ]);

        $this->seedType(LookupType::APARTMENT_LINE_SOURCE, [
            [ApartmentLineSource::RENT, 'Rent', 'blue'],
            [ApartmentLineSource::CLEANING_FEE, 'Cleaning Fee', 'orange'],
            [ApartmentLineSource::EXTRA_GUEST_FEE, 'Extra Guest Fee', 'orange'],
            [ApartmentLineSource::UTILITY, 'Utility', 'slate'],
            [ApartmentLineSource::SURCHARGE, 'Surcharge', 'gray'],
            [ApartmentLineSource::SERVICE_CHARGE, 'Service Charge', 'gray'],
            [ApartmentLineSource::VAT, 'VAT', 'gray'],
            [ApartmentLineSource::DISCOUNT, 'Discount', 'green'],
            [ApartmentLineSource::DAMAGE, 'Damage', 'red'],
            [ApartmentLineSource::ADJUSTMENT, 'Adjustment', 'gray'],
            [ApartmentLineSource::INSTALLMENT, 'Installment', 'purple'],
        ]);

        $this->seedType(LookupType::APARTMENT_BOOKING_STATUS, [
            [ApartmentBookingStatus::PENDING, 'Pending', 'gray'],
            [ApartmentBookingStatus::CONFIRMED, 'Confirmed', 'blue'],
            [ApartmentBookingStatus::CHECKED_IN, 'Checked In', 'green'],
            [ApartmentBookingStatus::CHECKED_OUT, 'Checked Out', 'slate'],
            [ApartmentBookingStatus::CANCELLED, 'Cancelled', 'red'],
            [ApartmentBookingStatus::NO_SHOW, 'No Show', 'orange'],
        ]);

        $this->seedType(LookupType::APARTMENT_BOOKING_CHANNEL, [
            [ApartmentBookingChannel::DIRECT, 'Direct', 'blue'],
            [ApartmentBookingChannel::AIRBNB, 'Airbnb', 'red'],
            [ApartmentBookingChannel::BOOKING_COM, 'Booking.com', 'blue'],
            [ApartmentBookingChannel::AGENT, 'Agent', 'purple'],
            [ApartmentBookingChannel::WALK_IN, 'Walk-in', 'slate'],
        ]);

        $this->seedType(LookupType::APARTMENT_LEASE_STATUS, [
            [ApartmentLeaseStatus::DRAFT, 'Draft', 'gray'],
            [ApartmentLeaseStatus::ACTIVE, 'Active', 'green'],
            [ApartmentLeaseStatus::RENEWED, 'Renewed', 'blue'],
            [ApartmentLeaseStatus::TERMINATED, 'Terminated', 'red'],
            [ApartmentLeaseStatus::ENDED, 'Ended', 'slate'],
        ]);

        $this->seedType(LookupType::APARTMENT_UTILITY_TYPE, [
            [ApartmentUtilityType::ELECTRICITY, 'Electricity', 'orange'],
            [ApartmentUtilityType::WATER, 'Water', 'blue'],
            [ApartmentUtilityType::GAS, 'Gas', 'red'],
        ]);

        $this->seedType(LookupType::TILL_SESSION_STATUS, [
            [TillSessionStatus::OPEN, 'Open', 'green'],
            [TillSessionStatus::CLOSED, 'Closed', 'slate'],
        ]);

        $this->seedType(LookupType::TILL_MOVEMENT_TYPE, [
            [TillMovementType::OPENING_BALANCE, 'Opening Balance', 'blue'],
            [TillMovementType::CASH_IN, 'Cash In', 'green'],
            [TillMovementType::CASH_OUT, 'Cash Out', 'red'],
            [TillMovementType::REFUND, 'Refund', 'orange'],
            [TillMovementType::EXPENSE, 'Expense', 'red'],
            [TillMovementType::TRANSFER, 'Transfer', 'purple'],
            [TillMovementType::CLOSING_ADJUSTMENT, 'Closing Adjustment', 'gray'],
        ]);

        $this->seedType(LookupType::APARTMENT_SALE_STATUS, [
            [ApartmentSaleStatus::INQUIRY, 'Inquiry', 'gray'],
            [ApartmentSaleStatus::RESERVED, 'Reserved', 'orange'],
            [ApartmentSaleStatus::AGREEMENT_SIGNED, 'Agreement Signed', 'blue'],
            [ApartmentSaleStatus::COMPLETED, 'Completed', 'green'],
            [ApartmentSaleStatus::CANCELLED, 'Cancelled', 'red'],
        ]);

        $this->seedType(LookupType::INVENTORY_KIND, [
            [InventoryKind::INGREDIENT, 'Ingredient', 'orange'],
            [InventoryKind::PRODUCT, 'Product', 'blue'],
        ]);

        $this->seedType(LookupType::GRN_STATUS, [
            [GrnStatus::DRAFT, 'Draft', 'gray'],
            [GrnStatus::RECEIVED, 'Received', 'green'],
            [GrnStatus::CANCELLED, 'Cancelled', 'red'],
        ]);

        $this->seedType(LookupType::STOCK_MOVEMENT_TYPE, [
            [StockMovementType::GRN_RECEIPT, 'GRN Receipt', 'green'],
            [StockMovementType::ADJUSTMENT, 'Adjustment', 'gray'],
            [StockMovementType::SALE, 'Sale', 'blue'],
            [StockMovementType::SALE_REVERSAL, 'Sale Reversal', 'orange'],
            [StockMovementType::WRITE_OFF, 'Write Off', 'red'],
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
