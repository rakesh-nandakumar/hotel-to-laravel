<?php

namespace App\Support;

/**
 * The licensable feature groups a tenant can be given access to from master
 * control — coarser than the ~40 fine-grained `module_key`s in
 * Database\Seeders\Menu\MenuDefinition (each catalog module bundles several).
 * A fine module_key not listed under any catalog module here (dashboard,
 * user_management_users, user_management_roles, audit_logs, hotel_staff,
 * account) is "core" — always enabled, never gated, so a platform operator
 * can never accidentally lock a tenant out of its own login/user management.
 *
 * See App\Services\TenantModules for the enforcement side.
 */
class ModuleCatalog
{
    public const HOTEL_OPERATIONS = 'hotel_operations';

    public const RESTAURANT_POS = 'restaurant_pos';

    public const APARTMENTS = 'apartments';

    public const PAYROLL = 'payroll';

    public const TILL = 'till';

    /**
     * @return array<string, array{name: string, description: string, module_keys: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            self::HOTEL_OPERATIONS => [
                'name' => 'Hotel Operations',
                'description' => 'Rooms, reservations, guests, housekeeping, maintenance, laundry, venues and front-desk reporting.',
                'module_keys' => [
                    'hotel_rooms', 'hotel_room_types', 'hotel_packages',
                    'hotel_guests', 'hotel_corporate',
                    'hotel_reservations', 'hotel_folios',
                    'hotel_housekeeping', 'hotel_maintenance', 'hotel_laundry',
                    'hotel_venues', 'hotel_venue_bookings',
                    'hotel_attendance', 'hotel_visitors', 'hotel_notifications',
                    'hotel_reports',
                ],
            ],
            self::RESTAURANT_POS => [
                'name' => 'Restaurant / POS',
                'description' => 'Menu management, point-of-sale ordering, dining tables, QR ordering and restaurant reporting.',
                'module_keys' => [
                    'hotel_menu_categories', 'hotel_menu_items', 'hotel_ingredients',
                    'hotel_products', 'hotel_grn',
                    'hotel_orders', 'hotel_dining_tables', 'hotel_qr_ordering',
                    'restaurant_reports',
                ],
            ],
            self::APARTMENTS => [
                'name' => 'Apartments',
                'description' => 'Apartment properties, units, leases, sales, tenant billing and related reporting.',
                'module_keys' => [
                    'apartment_properties', 'apartment_unit_types', 'apartment_units',
                    'apartment_customers', 'apartment_bookings', 'apartment_leases',
                    'apartment_sales', 'apartment_ledgers',
                    'apartment_housekeeping', 'apartment_maintenance', 'apartment_reports',
                ],
            ],
            self::PAYROLL => [
                'name' => 'Payroll',
                'description' => 'Staff pay runs, deductions and payslips.',
                'module_keys' => ['hotel_payroll'],
            ],
            self::TILL => [
                'name' => 'Till & Cash Management',
                'description' => 'Cash drawer sessions, cash-in/cash-out and till reconciliation.',
                'module_keys' => ['till'],
            ],
        ];
    }

    /**
     * The catalog module key a fine-grained module_key belongs to, or null
     * when it's core (always enabled — not covered by any catalog module).
     */
    public static function catalogKeyFor(string $fineModuleKey): ?string
    {
        foreach (self::definitions() as $catalogKey => $definition) {
            if (in_array($fineModuleKey, $definition['module_keys'], true)) {
                return $catalogKey;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }
}
