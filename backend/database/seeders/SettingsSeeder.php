<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\Lookups\SettingType;
use Illuminate\Database\Seeder;

/**
 * Default business settings. Idempotent by design (create-if-missing, never
 * overwrite): re-running this seeder must never clobber a value an admin has
 * since edited via the Settings screen.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            Setting::firstOrCreate(
                ['key' => $definition['key']],
                [
                    'value' => json_encode($definition['value']),
                    'type' => $definition['type'],
                    'category' => $definition['category'],
                    'label' => $definition['label'],
                    'hint' => $definition['hint'] ?? null,
                ],
            );
        }
    }

    /**
     * @return list<array{key: string, value: mixed, type: string, category: string, label: string, hint?: string}>
     */
    private function definitions(): array
    {
        return [
            // ── Hotel identity ───────────────────────────────────────────────
            ['key' => 'hotel.name', 'value' => 'Mount View Hotel', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Hotel Name'],
            ['key' => 'hotel.address', 'value' => '⚠ confirm with owner', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Address'],
            ['key' => 'hotel.phone', 'value' => '⚠ confirm with owner', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Phone'],
            ['key' => 'hotel.email', 'value' => '⚠ confirm with owner', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Email', 'hint' => 'Also receives low-stock/venue-inquiry alerts.'],
            ['key' => 'hotel.tax_reg_no', 'value' => '⚠ confirm with owner', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Tax Registration No.'],
            ['key' => 'hotel.website', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Website'],
            ['key' => 'hotel.logo_url', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'hotel', 'label' => 'Logo URL'],

            // ── Front desk ───────────────────────────────────────────────────
            ['key' => 'frontdesk.check_in_time', 'value' => '14:00', 'type' => SettingType::TIME, 'category' => 'frontdesk', 'label' => 'Check-in Time'],
            ['key' => 'frontdesk.check_out_time', 'value' => '12:00', 'type' => SettingType::TIME, 'category' => 'frontdesk', 'label' => 'Check-out Time'],

            // ── Billing ──────────────────────────────────────────────────────
            ['key' => 'billing.early_checkin_surcharge', 'value' => 0, 'type' => SettingType::MONEY, 'category' => 'billing', 'label' => 'Early Check-in Surcharge (LKR cents)'],
            ['key' => 'billing.late_checkout_surcharge', 'value' => 0, 'type' => SettingType::MONEY, 'category' => 'billing', 'label' => 'Late Check-out Surcharge (LKR cents)'],
            ['key' => 'billing.vat_pct', 'value' => 0, 'type' => SettingType::PERCENT, 'category' => 'billing', 'label' => 'VAT %'],
            ['key' => 'billing.service_charge_pct', 'value' => 0, 'type' => SettingType::PERCENT, 'category' => 'billing', 'label' => 'Service Charge %', 'hint' => 'Waived on takeaway POS orders.'],
            ['key' => 'billing.room_deposit_pct', 'value' => 20, 'type' => SettingType::PERCENT, 'category' => 'billing', 'label' => 'Room Booking Deposit %'],
            ['key' => 'billing.venue_deposit_pct', 'value' => 25, 'type' => SettingType::PERCENT, 'category' => 'billing', 'label' => 'Venue Booking Deposit %'],

            // ── Currency ─────────────────────────────────────────────────────
            ['key' => 'currency.usd_rate', 'value' => 300, 'type' => SettingType::NUMBER, 'category' => 'currency', 'label' => 'LKR per 1 USD (display only)'],

            // ── Policies ─────────────────────────────────────────────────────
            ['key' => 'policies.children_free_under_age', 'value' => 4, 'type' => SettingType::NUMBER, 'category' => 'policies', 'label' => 'Children Free Under Age'],
            ['key' => 'policies.parking_capacity', 'value' => 10, 'type' => SettingType::NUMBER, 'category' => 'policies', 'label' => 'Parking Capacity'],
            ['key' => 'policies.wifi_policy', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'policies', 'label' => 'WiFi Policy'],
            ['key' => 'policies.cancellation_policy', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'policies', 'label' => 'Cancellation Policy (guest-facing text)'],
            [
                'key' => 'policies.cancellation_rules', 'type' => SettingType::JSON, 'category' => 'policies',
                'label' => 'Cancellation Refund Tiers', 'hint' => 'Most-generous rule the booking still qualifies for wins.',
                'value' => [
                    ['daysBefore' => 7, 'refundPct' => 100],
                    ['daysBefore' => 3, 'refundPct' => 50],
                    ['daysBefore' => 0, 'refundPct' => 0],
                ],
            ],

            // ── Pricing ──────────────────────────────────────────────────────
            ['key' => 'pricing.weekend_days', 'value' => [0, 6], 'type' => SettingType::JSON, 'category' => 'pricing', 'label' => 'Weekend Days (0=Sun..6=Sat)'],
            ['key' => 'pricing.public_holidays', 'value' => [], 'type' => SettingType::JSON, 'category' => 'pricing', 'label' => 'Public Holidays', 'hint' => 'ISO date strings; priced as weekend rate.'],

            // ── Loyalty ──────────────────────────────────────────────────────
            ['key' => 'loyalty.points_per_1000lkr', 'value' => 0, 'type' => SettingType::NUMBER, 'category' => 'loyalty', 'label' => 'Points Earned per 1,000 LKR Spent', 'hint' => '0 disables loyalty accrual.'],
            ['key' => 'loyalty.point_value_cents', 'value' => 100, 'type' => SettingType::MONEY, 'category' => 'loyalty', 'label' => 'Point Redemption Value (LKR cents)'],
            ['key' => 'loyalty.redemption_catalog', 'value' => [], 'type' => SettingType::JSON, 'category' => 'loyalty', 'label' => 'Redemption Catalog'],

            // ── Notifications ────────────────────────────────────────────────
            ['key' => 'notifications.pre_arrival_days', 'value' => 1, 'type' => SettingType::NUMBER, 'category' => 'notifications', 'label' => 'Pre-arrival Reminder (days before check-in)'],
            ['key' => 'notifications.channels', 'value' => ['email', 'whatsapp', 'sms'], 'type' => SettingType::JSON, 'category' => 'notifications', 'label' => 'Enabled Guest Notification Channels'],

            // ── Payroll (Sri Lanka statutory defaults) ──────────────────────
            ['key' => 'payroll.epf_employee_pct', 'value' => 8, 'type' => SettingType::PERCENT, 'category' => 'payroll', 'label' => 'EPF — Employee %'],
            ['key' => 'payroll.epf_employer_pct', 'value' => 12, 'type' => SettingType::PERCENT, 'category' => 'payroll', 'label' => 'EPF — Employer %'],
            ['key' => 'payroll.etf_pct', 'value' => 3, 'type' => SettingType::PERCENT, 'category' => 'payroll', 'label' => 'ETF — Employer %'],
            ['key' => 'payroll.standard_monthly_hours', 'value' => 200, 'type' => SettingType::NUMBER, 'category' => 'payroll', 'label' => 'Standard Monthly Hours', 'hint' => 'Hours beyond this count as overtime.'],

            // ── Inventory ────────────────────────────────────────────────────
            ['key' => 'inventory.expiry_warn_days', 'value' => 3, 'type' => SettingType::NUMBER, 'category' => 'inventory', 'label' => 'Expiry Warning Window (days)'],

            // ── Integrations (System Admin only) ────────────────────────────
            ['key' => 'integrations.whatsapp_enabled', 'value' => false, 'type' => SettingType::BOOLEAN, 'category' => 'integrations', 'label' => 'WhatsApp Enabled'],
            ['key' => 'integrations.whatsapp_api_url', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'WhatsApp Cloud API URL'],
            ['key' => 'integrations.whatsapp_api_token', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'WhatsApp Cloud API Token'],
            ['key' => 'integrations.sms_enabled', 'value' => false, 'type' => SettingType::BOOLEAN, 'category' => 'integrations', 'label' => 'SMS Enabled'],
            ['key' => 'integrations.sms_api_url', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'SMS Gateway URL'],
            ['key' => 'integrations.sms_api_key', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'SMS Gateway API Key'],
            ['key' => 'integrations.sms_sender_id', 'value' => 'MountView', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'SMS Sender ID'],
            ['key' => 'integrations.bookingcom_hotel_id', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'Booking.com Hotel ID'],
            ['key' => 'integrations.bookingcom_api_key', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'Booking.com API Key'],
            ['key' => 'integrations.gateway_provider', 'value' => 'payhere', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'Payment Gateway Provider'],
            ['key' => 'integrations.gateway_merchant_id', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'Payment Gateway Merchant ID'],
            ['key' => 'integrations.gateway_secret', 'value' => '', 'type' => SettingType::TEXT, 'category' => 'integrations', 'label' => 'Payment Gateway Secret'],
        ];
    }
}
