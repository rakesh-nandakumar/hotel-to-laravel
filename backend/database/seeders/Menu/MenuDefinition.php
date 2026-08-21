<?php

namespace Database\Seeders\Menu;

class MenuDefinition
{
    /**
     * The application menu. Permissions are derived from this tree
     * (module_key.action), so adding a module here automatically registers its
     * permissions in the RBAC system.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tree(): array
    {
        return [
            [
                'name' => 'Dashboard',
                'icon' => 'layout-dashboard',
                'route_name' => 'dashboard',
                'module_key' => 'dashboard',
                'actions' => ['access'],
            ],
            [
                'name' => 'Administration',
                'icon' => 'shield-check',
                'children' => [
                    [
                        'name' => 'User Management',
                        'route_name' => 'user-management.users.index',
                        'module_key' => 'user_management_users',
                        'actions' => ['access', 'view', 'create', 'edit', 'delete', 'bulk_delete', 'unlock', 'reset_password'],
                    ],
                    [
                        'name' => 'Roles & Permissions',
                        'route_name' => 'user-management.roles.index',
                        'module_key' => 'user_management_roles',
                        'actions' => ['access', 'view', 'create', 'edit', 'delete', 'duplicate', 'toggle_active'],
                    ],
                ],
            ],
            [
                'name' => 'Audit Logs',
                'icon' => 'history',
                'route_name' => 'audit-logs.index',
                'module_key' => 'audit_logs',
                'actions' => ['access', 'view', 'export'],
            ],
            [
                'name' => 'Rooms',
                'icon' => 'bed-double',
                'children' => [
                    [
                        'name' => 'Rooms',
                        'route_name' => 'hotel.rooms.index',
                        'module_key' => 'hotel_rooms',
                        'actions' => ['access', 'create', 'edit', 'edit_status'],
                    ],
                    [
                        'name' => 'Room Types',
                        'route_name' => 'hotel.room-types.index',
                        'module_key' => 'hotel_room_types',
                        'actions' => ['access', 'create', 'edit'],
                    ],
                    [
                        'name' => 'Packages',
                        'route_name' => 'hotel.packages.index',
                        'module_key' => 'hotel_packages',
                        'actions' => ['access', 'edit'],
                    ],
                ],
            ],
            [
                'name' => 'Guests',
                'icon' => 'users-round',
                'route_name' => 'hotel.guests.index',
                'module_key' => 'hotel_guests',
                'actions' => ['access', 'view', 'create', 'edit', 'loyalty_adjust'],
            ],
            [
                'name' => 'Corporate Accounts',
                'icon' => 'building-2',
                'route_name' => 'hotel.corporate.index',
                'module_key' => 'hotel_corporate',
                'actions' => ['access', 'create', 'edit'],
            ],
            [
                'name' => 'Reservations',
                'icon' => 'calendar-check',
                'children' => [
                    [
                        'name' => 'Reservations',
                        'route_name' => 'hotel.reservations.index',
                        'module_key' => 'hotel_reservations',
                        'actions' => ['access', 'view', 'create', 'edit', 'check_in', 'checkout', 'cancel', 'discount'],
                    ],
                    [
                        'name' => 'Folios',
                        'route_name' => 'hotel.folios.show',
                        'module_key' => 'hotel_folios',
                        'actions' => ['view', 'add_line', 'void_line', 'payment', 'refund', 'invoice'],
                    ],
                ],
            ],
            [
                'name' => 'Restaurant Menu',
                'icon' => 'utensils',
                'children' => [
                    [
                        'name' => 'Categories',
                        'route_name' => 'hotel.menu.categories.index',
                        'module_key' => 'hotel_menu_categories',
                        'actions' => ['access', 'create', 'edit', 'delete'],
                    ],
                    [
                        'name' => 'Items',
                        'route_name' => 'hotel.menu.items.index',
                        'module_key' => 'hotel_menu_items',
                        'actions' => ['access', 'create', 'edit', 'delete', 'sold_out'],
                    ],
                ],
            ],
            [
                'name' => 'Inventory',
                'icon' => 'boxes',
                'children' => [
                    [
                        'name' => 'Ingredients',
                        'route_name' => 'hotel.ingredients.index',
                        'module_key' => 'hotel_ingredients',
                        'actions' => ['access', 'create', 'edit', 'delete', 'adjust_stock', 'write_off'],
                    ],
                    [
                        'name' => 'Products',
                        'route_name' => 'hotel.products.index',
                        'module_key' => 'hotel_products',
                        'actions' => ['access', 'create', 'edit', 'delete', 'adjust_stock'],
                    ],
                    [
                        'name' => 'Goods Received Notes',
                        'route_name' => 'hotel.grns.index',
                        'module_key' => 'hotel_grn',
                        'actions' => ['access', 'view', 'create', 'edit', 'delete', 'receive'],
                    ],
                ],
            ],
            [
                'name' => 'POS Orders',
                'icon' => 'shopping-cart',
                'route_name' => 'hotel.orders.index',
                'module_key' => 'hotel_orders',
                'actions' => ['access', 'view', 'create', 'kot', 'void_item', 'hold', 'discount', 'settle', 'charge_to_room', 'void', 'refund', 'receipt', 'slip', 'kot_ticket', 'split', 'merge', 'delivery_dispatch'],
            ],
            [
                'name' => 'Dining Tables',
                'icon' => 'grid-2x2',
                'route_name' => 'hotel.dining-tables.index',
                'module_key' => 'hotel_dining_tables',
                'actions' => ['access', 'create', 'edit', 'edit_status'],
            ],
            [
                'name' => 'QR Ordering',
                'icon' => 'qr-code',
                'route_name' => 'hotel.qr-ordering.index',
                'module_key' => 'hotel_qr_ordering',
                'actions' => ['access', 'create', 'edit', 'regenerate'],
            ],
            [
                'name' => 'Housekeeping',
                'icon' => 'sparkles',
                'route_name' => 'hotel.housekeeping.tasks.index',
                'module_key' => 'hotel_housekeeping',
                'actions' => ['access', 'create', 'assign', 'checklist', 'complete'],
            ],
            [
                'name' => 'Maintenance',
                'icon' => 'wrench',
                'route_name' => 'hotel.maintenance.index',
                'module_key' => 'hotel_maintenance',
                'actions' => ['access', 'create', 'edit'],
            ],
            [
                'name' => 'Laundry',
                'icon' => 'shirt',
                'route_name' => 'hotel.laundry.items.index',
                'module_key' => 'hotel_laundry',
                'actions' => ['access', 'create', 'edit', 'charge'],
            ],
            [
                'name' => 'Venues',
                'icon' => 'party-popper',
                'children' => [
                    [
                        'name' => 'Venues',
                        'route_name' => 'hotel.venues.index',
                        'module_key' => 'hotel_venues',
                        'actions' => ['access', 'edit'],
                    ],
                    [
                        'name' => 'Bookings',
                        'route_name' => 'hotel.venues.bookings.index',
                        'module_key' => 'hotel_venue_bookings',
                        'actions' => ['access', 'view', 'create', 'edit', 'confirm', 'complete', 'cancel'],
                    ],
                ],
            ],
            [
                'name' => 'Till',
                'icon' => 'clock',
                'route_name' => 'till.current',
                'module_key' => 'till',
                'actions' => ['access', 'open', 'close', 'close_any', 'cash_in', 'cash_out', 'manage'],
            ],
            [
                'name' => 'Attendance',
                'icon' => 'calendar-check-2',
                'route_name' => 'hotel.attendance.index',
                'module_key' => 'hotel_attendance',
                'actions' => ['access', 'on_duty', 'view_all', 'export'],
            ],
            [
                'name' => 'Payroll',
                'icon' => 'banknote',
                'route_name' => 'hotel.payroll.runs.index',
                'module_key' => 'hotel_payroll',
                'actions' => ['manage_pay', 'view', 'generate', 'adjust_line', 'finalize', 'delete_run', 'mark_paid', 'export', 'payslip'],
            ],
            [
                'name' => 'Visitors',
                'icon' => 'log-in',
                'route_name' => 'hotel.visitors.index',
                'module_key' => 'hotel_visitors',
                'actions' => ['access', 'create', 'sign_out'],
            ],
            [
                'name' => 'Notifications',
                'icon' => 'bell',
                'route_name' => 'hotel.notifications.index',
                'module_key' => 'hotel_notifications',
                'actions' => ['access', 'test', 'run_scheduled'],
            ],
            [
                'name' => 'Reports',
                'icon' => 'bar-chart-3',
                'route_name' => 'hotel.reports.dashboard',
                'module_key' => 'hotel_reports',
                // 'pos' deliberately lives under 'restaurant_reports' now — the POS
                // sales card moved to the Restaurant Reports hub, not this one.
                'actions' => [
                    'dashboard', 'daily', 'monthly', 'night_audit_run', 'night_audit_view',
                    'revpar', 'channel_mix', 'cancellations', 'guest_loyalty', 'corporate_ar',
                    'ops_sla', 'payroll_cost', 'venues', 'laundry',
                ],
            ],
            [
                'name' => 'Restaurant Reports',
                'icon' => 'chart-line',
                'route_name' => 'hotel.reports.pos',
                'module_key' => 'restaurant_reports',
                'actions' => [
                    'pos', 'menu_performance', 'modifiers', 'discounts_voids', 'table_server',
                    'delivery_performance', 'kitchen_ticket_time', 'shift_sales', 'food_cost',
                ],
            ],
            [
                'name' => 'Staff PIN Unlock',
                'icon' => 'key-round',
                'route_name' => 'hotel.staff.pin.update',
                'module_key' => 'hotel_staff',
                'actions' => ['set_pin'],
            ],
            [
                'name' => 'Apartments',
                'icon' => 'building',
                'children' => [
                    [
                        'name' => 'Properties',
                        'route_name' => 'apartments.properties.index',
                        'module_key' => 'apartment_properties',
                        'actions' => ['access', 'create', 'edit'],
                    ],
                    [
                        'name' => 'Unit Types',
                        'route_name' => 'apartments.unit-types.index',
                        'module_key' => 'apartment_unit_types',
                        'actions' => ['access', 'create', 'edit'],
                    ],
                    [
                        'name' => 'Units',
                        'route_name' => 'apartments.units.index',
                        'module_key' => 'apartment_units',
                        'actions' => ['access', 'create', 'edit', 'edit_status'],
                    ],
                    [
                        'name' => 'Customers',
                        'route_name' => 'apartments.customers.index',
                        'module_key' => 'apartment_customers',
                        'actions' => ['access', 'view', 'create', 'edit'],
                    ],
                    [
                        'name' => 'Bookings',
                        'route_name' => 'apartments.bookings.index',
                        'module_key' => 'apartment_bookings',
                        'actions' => ['access', 'view', 'create', 'check_in', 'checkout', 'cancel'],
                    ],
                    [
                        'name' => 'Leases',
                        'route_name' => 'apartments.leases.index',
                        'module_key' => 'apartment_leases',
                        'actions' => ['access', 'view', 'create', 'renew', 'terminate', 'utility_reading'],
                    ],
                    [
                        'name' => 'Sales',
                        'route_name' => 'apartments.sales.index',
                        'module_key' => 'apartment_sales',
                        'actions' => ['access', 'view', 'create', 'reserve', 'sign_agreement', 'complete', 'cancel'],
                    ],
                    [
                        'name' => 'Ledgers',
                        'route_name' => 'apartments.ledgers.show',
                        'module_key' => 'apartment_ledgers',
                        'actions' => ['view', 'add_line', 'void_line', 'payment', 'refund'],
                    ],
                    [
                        'name' => 'Housekeeping',
                        'route_name' => 'apartments.housekeeping.tasks.index',
                        'module_key' => 'apartment_housekeeping',
                        'actions' => ['access', 'create', 'assign', 'checklist', 'complete'],
                    ],
                    [
                        'name' => 'Maintenance',
                        'route_name' => 'apartments.maintenance.index',
                        'module_key' => 'apartment_maintenance',
                        'actions' => ['access', 'create', 'edit'],
                    ],
                    [
                        'name' => 'Reports',
                        'route_name' => 'apartments.reports.dashboard',
                        'module_key' => 'apartment_reports',
                        'actions' => [
                            'dashboard', 'occupancy_trend', 'revenue_channel', 'rent_roll',
                            'sales_pipeline', 'utilities', 'ops_sla',
                        ],
                    ],
                ],
            ],
        ];
    }
}
