<?php

/*
|--------------------------------------------------------------------------
| Tenant clone topology (Test Instances)
|--------------------------------------------------------------------------
|
| The schema map used by App\Services\Tenancy\TestInstanceService to copy every
| tenant-scoped row of a live tenant into its isolated test instance, remapping
| primary keys so both environments coexist in the same tables.
|
| Rules encoded here:
|  - Only columns listed as `fks` are remapped; everything else (global
|    references like lookups.id / central_admins.id / permission ids) is copied
|    verbatim on purpose.
|  - Tables listed in `excluded` are operational, device- or audit-related rows
|    that must NOT leak into a test environment (device tokens and
|    impersonation tokens are security credentials, audit trails are
|    per-environment operational history).
|  - `pivots` are tables without tenant_id whose isolation is inherited from
|    their tenant-scoped parents; their rows are copied/erased by membership in
|    the mapped id sets; keys omitted from `skip_remap` reference global tables
|    and are copied verbatim.
|  - `polymorphic` maps morph-type columns whose `*_id` may reference one of
|    several cloned tables; the id is remapped only when the type resolves to a
|    cloned table.
|
| Tables are topologically ordered at runtime from these `fks`; any remaining
| cycle edge (users <-> roles via created_by/updated_by, orders.parent_order_id)
| is resolved in a second pass after every id map exists.
*/

return [

    'tables' => [
        // --- Users / roles / branches (cycle: roles.created_by <-> users.role_id) ---
        'roles' => [
            'fks' => ['created_by' => 'users', 'updated_by' => 'users'],
        ],
        'users' => [
            'fks' => ['role_id' => 'roles'],
        ],
        'warehouses' => [
            'fks' => ['manager_user_id' => 'users', 'created_by' => 'users', 'updated_by' => 'users', 'deleted_by' => 'users'],
        ],
        'settings' => ['fks' => ['updated_by' => 'users']],
        'tenant_modules' => ['fks' => []],

        // --- People / parties ---
        'guests' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'apartment_customers' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'corporate_accounts' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'group_bookings' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],

        // --- Rooms / hotel catalog ---
        'room_types' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'rooms' => [
            'fks' => ['room_type_id' => 'room_types', 'branch_id' => 'warehouses', 'created_by' => 'users', 'updated_by' => 'users'],
        ],
        'seasonal_rates' => ['fks' => ['room_type_id' => 'room_types', 'created_by' => 'users', 'updated_by' => 'users']],

        // --- Dining ---
        'dining_areas' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'dining_tables' => ['fks' => ['dining_area_id' => 'dining_areas', 'created_by' => 'users', 'updated_by' => 'users']],

        // --- Menu / kitchen ---
        'ingredients' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'ingredient_batches' => ['fks' => ['ingredient_id' => 'ingredients']],
        'pos_menu_categories' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'pos_menu_items' => [
            'fks' => ['menu_category_id' => 'pos_menu_categories', 'stock_ingredient_id' => 'ingredients', 'created_by' => 'users', 'updated_by' => 'users'],
        ],
        'add_ons' => ['fks' => ['stock_ingredient_id' => 'ingredients', 'created_by' => 'users', 'updated_by' => 'users']],
        'add_on_links' => [
            'fks' => ['add_on_id' => 'add_ons', 'menu_item_id' => 'pos_menu_items', 'menu_category_id' => 'pos_menu_categories'],
        ],
        'recipe_items' => ['fks' => ['menu_item_id' => 'pos_menu_items', 'ingredient_id' => 'ingredients']],
        'menu_item_modifier_groups' => ['fks' => ['menu_item_id' => 'pos_menu_items', 'created_by' => 'users', 'updated_by' => 'users']],
        'menu_item_modifiers' => ['fks' => ['modifier_group_id' => 'menu_item_modifier_groups']],

        // --- POS / orders / folios / payments ---
        'orders' => [
            'fks' => [
                'parent_order_id' => 'orders',
                'dining_table_id' => 'dining_tables',
                'room_id' => 'rooms',
                'reservation_id' => 'reservations',
                'delivery_rider_id' => 'users',
                'discount_by_id' => 'users',
                'staff_id' => 'users',
            ],
        ],
        'order_items' => [
            'fks' => ['order_id' => 'orders', 'menu_item_id' => 'pos_menu_items', 'add_on_id' => 'add_ons'],
        ],
        'order_item_modifiers' => ['fks' => ['order_item_id' => 'order_items', 'menu_item_modifier_id' => 'menu_item_modifiers']],
        'tills' => ['fks' => ['branch_id' => 'warehouses', 'created_by' => 'users', 'updated_by' => 'users']],
        'till_sessions' => ['fks' => ['till_id' => 'tills', 'opened_by' => 'users', 'closed_by' => 'users']],
        'till_movements' => ['fks' => ['till_session_id' => 'till_sessions', 'performed_by' => 'users', 'approved_by' => 'users']],
        'reservations' => [
            'fks' => [
                'guest_id' => 'guests',
                'package_id' => 'packages',
                'group_booking_id' => 'group_bookings',
                'corporate_account_id' => 'corporate_accounts',
                'created_by' => 'users',
                'updated_by' => 'users',
            ],
        ],
        'reservation_rooms' => ['fks' => ['reservation_id' => 'reservations', 'room_id' => 'rooms', 'bill_to_guest_id' => 'guests']],
        'folios' => ['fks' => ['reservation_id' => 'reservations', 'venue_booking_id' => 'venue_bookings']],
        'folio_lines' => ['fks' => ['folio_id' => 'folios', 'order_id' => 'orders', 'staff_id' => 'users']],
        'payments' => [
            'fks' => [
                'folio_id' => 'folios',
                'order_id' => 'orders',
                'corporate_account_id' => 'corporate_accounts',
                'till_session_id' => 'till_sessions',
                'staff_id' => 'users',
            ],
        ],

        // --- Venues / events ---
        'venues' => ['fks' => ['branch_id' => 'warehouses', 'created_by' => 'users', 'updated_by' => 'users']],
        'venue_bookings' => ['fks' => ['venue_id' => 'venues', 'guest_id' => 'guests', 'created_by' => 'users', 'updated_by' => 'users']],

        // --- Housekeeping / maintenance / QR ---
        'housekeeping_tasks' => ['fks' => ['room_id' => 'rooms', 'reservation_id' => 'reservations', 'assigned_to_id' => 'users']],
        'maintenance_issues' => ['fks' => ['room_id' => 'rooms', 'venue_id' => 'venues', 'logged_by_id' => 'users']],
        'room_item_checks' => ['fks' => ['reservation_id' => 'reservations', 'room_id' => 'rooms', 'staff_id' => 'users']],
        'qr_ordering_points' => [
            'fks' => ['room_id' => 'rooms', 'dining_table_id' => 'dining_tables', 'created_by' => 'users', 'updated_by' => 'users'],
            'unique_columns' => ['token'],
        ],

        // --- Loyalty / operations / HR ---
        'loyalty_transactions' => ['fks' => ['guest_id' => 'guests', 'staff_id' => 'users']],
        'attendances' => ['fks' => ['user_id' => 'users']],
        'visitor_logs' => ['fks' => ['logged_by_id' => 'users']],
        'night_audits' => ['fks' => ['run_by_id' => 'users']],
        'payroll_runs' => ['fks' => ['run_by_id' => 'users']],
        'payroll_lines' => ['fks' => ['run_id' => 'payroll_runs', 'user_id' => 'users']],
        'packages' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],

        // --- Apartment module ---
        'apartment_properties' => ['fks' => ['branch_id' => 'warehouses', 'created_by' => 'users', 'updated_by' => 'users']],
        'apartment_unit_types' => ['fks' => ['created_by' => 'users', 'updated_by' => 'users']],
        'apartment_units' => [
            'fks' => ['property_id' => 'apartment_properties', 'unit_type_id' => 'apartment_unit_types', 'created_by' => 'users', 'updated_by' => 'users'],
        ],
        'apartment_seasonal_rates' => ['fks' => ['unit_type_id' => 'apartment_unit_types', 'created_by' => 'users', 'updated_by' => 'users']],
        'apartment_bookings' => ['fks' => ['unit_id' => 'apartment_units', 'customer_id' => 'apartment_customers', 'created_by' => 'users', 'updated_by' => 'users']],
        'apartment_leases' => ['fks' => ['unit_id' => 'apartment_units', 'customer_id' => 'apartment_customers', 'created_by' => 'users', 'updated_by' => 'users']],
        'apartment_sales' => ['fks' => ['unit_id' => 'apartment_units', 'customer_id' => 'apartment_customers', 'created_by' => 'users', 'updated_by' => 'users']],
        'apartment_ledgers' => ['fks' => ['booking_id' => 'apartment_bookings', 'lease_id' => 'apartment_leases', 'sale_id' => 'apartment_sales']],
        'apartment_ledger_lines' => ['fks' => ['ledger_id' => 'apartment_ledgers', 'staff_id' => 'users']],
        'apartment_lease_rent_charges' => ['fks' => ['lease_id' => 'apartment_leases', 'ledger_line_id' => 'apartment_ledger_lines']],
        'apartment_utility_readings' => ['fks' => ['lease_id' => 'apartment_leases', 'ledger_line_id' => 'apartment_ledger_lines', 'staff_id' => 'users']],
        'apartment_payments' => ['fks' => ['ledger_id' => 'apartment_ledgers', 'till_session_id' => 'till_sessions', 'staff_id' => 'users']],
        'apartment_housekeeping_tasks' => [
            'fks' => ['unit_id' => 'apartment_units', 'booking_id' => 'apartment_bookings', 'lease_id' => 'apartment_leases', 'assigned_to_id' => 'users'],
        ],
        'apartment_maintenance_issues' => ['fks' => ['unit_id' => 'apartment_units', 'logged_by_id' => 'users']],
    ],

    /*
    | Pivot tables without their own tenant_id whose isolation is inherited from
    | their tenant-scoped parents. Rows are copied/erased by membership in the
    | mapped id sets; keys omitted from `skip_remap` reference global tables and
    | are copied verbatim.
    */

    'pivots' => [
        'user_roles' => ['fks' => ['user_id' => 'users', 'role_id' => 'roles']],
        'role_permissions' => ['fks' => ['role_id' => 'roles'], 'skip_remap' => ['permission_id']],
        'user_warehouse_access' => ['fks' => ['user_id' => 'users', 'warehouse_id' => 'warehouses']],
        'user_permission_overrides' => ['fks' => ['user_id' => 'users', 'granted_by' => 'users'], 'skip_remap' => ['permission_id']],
    ],

    /*
    | Tables intentionally not cloned into a test instance. Audit trails are
    | per-environment operational history (and polymorphic); device tokens and
    | impersonation tokens are security credentials whose duplication would
    | defeat impersonation's one-time nature; notifications are transient
    | operational rows; Sanctum tokens/sessions belong to physical devices.
    */

    'excluded' => [
        'audit_logs',
        'device_tokens',
        'impersonation_tokens',
        'notifications',
        'passkeys',
        'personal_access_tokens',
        'sessions',
    ],

    /*
    | Morph-type id columns. The id is remapped only when the resolved type
    | class maps to a cloned table; references to global classes keep their
    | value.
    */

    'polymorphic' => [
        'till_movements' => ['type' => 'source_type', 'id' => 'source_id'],
        'loyalty_transactions' => ['type' => 'ref_type', 'id' => 'ref_id'],
    ],
];
