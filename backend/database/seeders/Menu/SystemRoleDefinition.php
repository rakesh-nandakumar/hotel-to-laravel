<?php

namespace Database\Seeders\Menu;

class SystemRoleDefinition
{
    /**
     * Baseline system roles. Every permission referenced here must correspond to
     * a module_key.action declared in {@see MenuDefinition}; the seeder throws if
     * one is missing.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function roles(): array
    {
        return [
            'Full Administrator' => [
                'description' => 'Full, unrestricted access to every module.',
                'is_full_admin' => true,
                'permissions' => 'all',
            ],
            'Manager' => [
                'description' => 'Manages users and roles, with dashboard access.',
                'is_full_admin' => false,
                'permissions' => [
                    'dashboard' => ['access'],
                    'user_management_users' => ['access', 'view', 'create', 'edit'],
                    'user_management_roles' => ['access', 'view'],
                    'audit_logs' => ['access', 'view'],
                    'hotel_rooms' => ['access', 'create', 'edit', 'edit_status'],
                    'hotel_room_types' => ['access', 'create', 'edit'],
                    'hotel_packages' => ['access', 'edit'],
                    'hotel_guests' => ['access', 'view', 'create', 'edit', 'loyalty_adjust'],
                    'hotel_corporate' => ['access', 'create', 'edit'],
                    'hotel_reservations' => ['access', 'view', 'create', 'edit', 'check_in', 'checkout', 'cancel'],
                    'hotel_folios' => ['view', 'add_line', 'void_line', 'payment', 'refund', 'invoice'],
                    'hotel_menu_categories' => ['access', 'create', 'edit', 'delete'],
                    'hotel_menu_items' => ['access', 'create', 'edit', 'delete', 'sold_out'],
                    'hotel_ingredients' => ['access', 'create', 'edit', 'delete', 'adjust_stock', 'write_off'],
                    'hotel_orders' => ['access', 'view', 'create', 'kot', 'void_item', 'hold', 'discount', 'settle', 'charge_to_room', 'void', 'refund', 'receipt', 'slip', 'kot_ticket'],
                    'hotel_housekeeping' => ['access', 'create', 'assign', 'checklist', 'complete'],
                    'hotel_maintenance' => ['access', 'create', 'edit'],
                    'hotel_laundry' => ['access', 'create', 'edit', 'charge'],
                    'hotel_venues' => ['access', 'edit'],
                    'hotel_venue_bookings' => ['access', 'view', 'create', 'edit', 'confirm', 'complete', 'cancel'],
                    'hotel_shifts' => ['access', 'open', 'close', 'close_any'],
                    'hotel_attendance' => ['access', 'on_duty', 'view_all', 'export'],
                    'hotel_visitors' => ['access', 'create', 'sign_out'],
                    // hotel_notifications.test is deliberately NOT granted to any named
                    // role — it mirrors Node's requireSystemAdmin (strict SYSTEM_ADMIN
                    // only, no OWNER bypass), reachable here only via Full Administrator's
                    // is_full_admin bypass.
                    'hotel_notifications' => ['access', 'run_scheduled'],
                    'hotel_reports' => ['dashboard', 'daily', 'monthly', 'pos', 'night_audit_run', 'night_audit_view'],
                    'hotel_staff' => ['set_pin'],
                    'hotel_settings' => ['access', 'update'],
                ],
            ],
            'Auditor' => [
                'description' => 'Read-only access to the dashboard and audit logs.',
                'is_full_admin' => false,
                'permissions' => [
                    'dashboard' => ['access'],
                    'audit_logs' => ['access', 'view', 'export'],
                ],
            ],

            // ── Mount View Hotel operational roles ──────────────────────────
            // Node's SYSTEM_ADMIN maps to "Full Administrator" above (unconditional
            // bypass — including the integrations-only gate). Node's OWNER bypasses
            // every regular MANAGER-gated route but NOT the strict integrations gate,
            // so it is a normal (non-full-admin) role with a broad, explicit grant
            // list — extended module-by-module as each one is built, mirroring the
            // requireRole(...) checks documented in the Node route files.
            'Owner' => [
                'description' => 'Hotel owner — full operational access; integrations remain System-Admin-only.',
                'is_full_admin' => false,
                'permissions' => [
                    'dashboard' => ['access'],
                    'hotel_rooms' => ['access', 'create', 'edit', 'edit_status'],
                    'hotel_room_types' => ['access', 'create', 'edit'],
                    'hotel_packages' => ['access', 'edit'],
                    'hotel_guests' => ['access', 'view', 'create', 'edit', 'loyalty_adjust'],
                    'hotel_corporate' => ['access', 'create', 'edit'],
                    'hotel_reservations' => ['access', 'view', 'create', 'edit', 'check_in', 'checkout', 'cancel'],
                    'hotel_folios' => ['view', 'add_line', 'void_line', 'payment', 'refund', 'invoice'],
                    'hotel_menu_categories' => ['access', 'create', 'edit', 'delete'],
                    'hotel_menu_items' => ['access', 'create', 'edit', 'delete', 'sold_out'],
                    'hotel_ingredients' => ['access', 'create', 'edit', 'delete', 'adjust_stock', 'write_off'],
                    'hotel_orders' => ['access', 'view', 'create', 'kot', 'void_item', 'hold', 'discount', 'settle', 'charge_to_room', 'void', 'refund', 'receipt', 'slip', 'kot_ticket'],
                    'hotel_housekeeping' => ['access', 'create', 'assign', 'checklist', 'complete'],
                    'hotel_maintenance' => ['access', 'create', 'edit'],
                    'hotel_laundry' => ['access', 'create', 'edit', 'charge'],
                    'hotel_venues' => ['access', 'edit'],
                    'hotel_venue_bookings' => ['access', 'view', 'create', 'edit', 'confirm', 'complete', 'cancel'],
                    'hotel_shifts' => ['access', 'open', 'close', 'close_any'],
                    'hotel_attendance' => ['access', 'on_duty', 'view_all', 'export'],
                    // Payroll is OWNER-only — Node explicitly excludes Manager here
                    // (router.use(requireRole("OWNER"))), unlike every other module.
                    'hotel_payroll' => ['manage_pay', 'view', 'generate', 'adjust_line', 'finalize', 'delete_run', 'mark_paid', 'export', 'payslip'],
                    'hotel_visitors' => ['access', 'create', 'sign_out'],
                    'hotel_notifications' => ['access', 'run_scheduled'],
                    'hotel_reports' => ['dashboard', 'daily', 'monthly', 'pos', 'night_audit_run', 'night_audit_view'],
                    'hotel_staff' => ['set_pin'],
                    'hotel_settings' => ['access', 'update'],
                ],
            ],
            'Housekeeper' => [
                'description' => 'Room cleaning tasks and status.',
                'is_full_admin' => false,
                'permissions' => [
                    'dashboard' => ['access'],
                    'hotel_rooms' => ['access', 'edit_status'],
                    'hotel_room_types' => ['access'],
                    'hotel_packages' => ['access'],
                    'hotel_menu_categories' => ['access'],
                    'hotel_menu_items' => ['access'],
                    'hotel_housekeeping' => ['access', 'checklist', 'complete'],
                    'hotel_maintenance' => ['access', 'create', 'edit'],
                    'hotel_laundry' => ['access', 'charge'],
                    'hotel_attendance' => ['access'],
                    'hotel_settings' => ['access'],
                ],
            ],
            'Chef' => [
                'description' => 'Kitchen order tickets and menu/inventory.',
                'is_full_admin' => false,
                'permissions' => [
                    'dashboard' => ['access'],
                    'hotel_rooms' => ['access'],
                    'hotel_room_types' => ['access'],
                    'hotel_packages' => ['access'],
                    'hotel_menu_categories' => ['access'],
                    'hotel_menu_items' => ['access', 'sold_out'],
                    'hotel_ingredients' => ['access', 'create', 'edit', 'adjust_stock', 'write_off'],
                    'hotel_orders' => ['access', 'view', 'kot', 'receipt', 'slip', 'kot_ticket'],
                    'hotel_maintenance' => ['access', 'create', 'edit'],
                    'hotel_attendance' => ['access'],
                    'hotel_settings' => ['access'],
                ],
            ],
            'Security' => [
                'description' => 'Visitor log and maintenance reporting.',
                'is_full_admin' => false,
                'permissions' => [
                    'dashboard' => ['access'],
                    'hotel_rooms' => ['access'],
                    'hotel_room_types' => ['access'],
                    'hotel_packages' => ['access'],
                    'hotel_menu_categories' => ['access'],
                    'hotel_menu_items' => ['access'],
                    'hotel_maintenance' => ['access', 'create', 'edit'],
                    'hotel_attendance' => ['access'],
                    'hotel_visitors' => ['access', 'create', 'sign_out'],
                    'hotel_settings' => ['access'],
                ],
            ],
        ];
    }
}
