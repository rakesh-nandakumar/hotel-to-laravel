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
                        'actions' => ['access', 'view', 'create', 'edit', 'check_in', 'checkout', 'cancel'],
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
                    [
                        'name' => 'Ingredients',
                        'route_name' => 'hotel.ingredients.index',
                        'module_key' => 'hotel_ingredients',
                        'actions' => ['access', 'create', 'edit', 'delete', 'adjust_stock', 'write_off'],
                    ],
                ],
            ],
            [
                'name' => 'POS Orders',
                'icon' => 'shopping-cart',
                'route_name' => 'hotel.orders.index',
                'module_key' => 'hotel_orders',
                'actions' => ['access', 'view', 'create', 'kot', 'void_item', 'hold', 'discount', 'settle', 'charge_to_room', 'void', 'refund', 'receipt', 'slip', 'kot_ticket'],
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
                'name' => 'Shifts',
                'icon' => 'clock',
                'route_name' => 'hotel.shifts.index',
                'module_key' => 'hotel_shifts',
                'actions' => ['access', 'open', 'close', 'close_any'],
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
                'actions' => ['dashboard', 'daily', 'monthly', 'pos', 'night_audit_run', 'night_audit_view'],
            ],
            [
                'name' => 'Staff PIN Unlock',
                'icon' => 'key-round',
                'route_name' => 'hotel.staff.pin.update',
                'module_key' => 'hotel_staff',
                'actions' => ['set_pin'],
            ],
            [
                'name' => 'Hotel Settings',
                'icon' => 'settings',
                'route_name' => 'hotel.settings.index',
                'module_key' => 'hotel_settings',
                'actions' => ['access', 'update'],
            ],
        ];
    }
}
