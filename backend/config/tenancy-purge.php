<?php

/*
|--------------------------------------------------------------------------
| Tenant data purge catalog (Master Control → tenant → Data)
|--------------------------------------------------------------------------
|
| Drives App\Services\Tenancy\DataPurge\*. The engine does NOT hand-maintain a
| table graph: it reads the real foreign keys from the live schema, so a new
| table or FK is picked up automatically. This file only holds what a schema
| cannot express:
|
|  - the operator-facing categories and their filters;
|  - which tables must never be purged (`protected`);
|  - `edge_overrides`: FKs declared SET NULL that in business terms mean
|    "belongs to" (an invoice belongs to its reservation);
|  - `owners`: child rows that must never be left as a fragment of a
|    document (an order with some of its items missing);
|  - `loose_edges`: polymorphic (type + id) references that have no FK;
|  - `document_numbers`: unique human-facing codes (renumbering on restore,
|    "continue numbering" floors).
|
| Default policy per FK, taken from its ON DELETE rule:
|    CASCADE / RESTRICT / NO ACTION  → cascade  (dependents are deleted with it,
|                                                 and shown to the operator)
|    SET NULL                        → nullify  (dependents are kept, unlinked)
|
| tests/Feature/Central/TenantDataPurgeCoverageTest.php fails when a tenant
| table is neither reachable from a category nor listed as protected, so a new
| table cannot silently escape this catalog.
*/

return [

    'retention_days' => (int) env('TENANT_PURGE_RETENTION_DAYS', 90),

    // A destructive request is refused unless this many seconds passed since the
    // preview that produced its token (the UI enforces the countdown itself).
    'min_confirm_seconds' => 3,

    // A preview/restore token is only honoured for this long.
    'token_ttl_seconds' => 900,

    'backup_disk' => 'local',

    'backup_directory' => 'tenant-data-backups',

    'modules' => [
        'hotel' => 'Hotel operations',
        'restaurant' => 'Restaurant / POS',
        'inventory' => 'Inventory',
        'apartments' => 'Apartments',
        'payroll' => 'Payroll & attendance',
        'till' => 'Till & cash',
        'reset' => 'Tenant Reset',
    ],

    /*
    | Tables that can never be purged, whatever the operator selects: identity
    | and access, configuration, audit trail, and the purge machinery's own
    | bookkeeping. Reaching one of these aborts the purge.
    */

    'protected' => [
        'users',
        'roles',
        'settings',
        'tenant_modules',
        'tills',
        'audit_logs',
        'device_tokens',
        'impersonation_tokens',
        'passkeys',
        'personal_access_tokens',
        'sessions',
        'tenant_data_purges',
        'tenant_document_number_floors',
    ],

    /*
    | Filter specs: `column` is one column for every root of the category, or a
    | [table => column] map when the roots name it differently.
    | Types: date (from/to, inclusive) | month (YYYY-MM from/to) | lookup (ids of a lookup type).
    */

    'categories' => [

        // ── Hotel ──────────────────────────────────────────────────────────
        'hotel_reservations' => [
            'module' => 'hotel',
            'label' => 'Reservations & hotel invoices',
            'description' => 'Stays with their room assignments, invoice (folio), charge lines, payments and loyalty earnings.',
            'roots' => ['reservations'],
            'filters' => [
                ['key' => 'check_in', 'label' => 'Check-in date', 'type' => 'date', 'column' => 'check_in'],
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'lookup', 'column' => 'reservation_status_id', 'lookup' => 'reservation_status'],
            ],
        ],
        'hotel_group_bookings' => [
            'module' => 'hotel',
            'label' => 'Group bookings',
            'description' => 'Group booking headers. Their reservations are kept and simply become individual bookings.',
            'roots' => ['group_bookings'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
            ],
        ],
        'hotel_venue_bookings' => [
            'module' => 'hotel',
            'label' => 'Venue bookings & event invoices',
            'description' => 'Event bookings with their extra charges, invoice, payments and loyalty earnings.',
            'roots' => ['venue_bookings'],
            'filters' => [
                ['key' => 'event', 'label' => 'Event date', 'type' => 'date', 'column' => 'date'],
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'lookup', 'column' => 'venue_booking_status_id', 'lookup' => 'venue_booking_status'],
            ],
        ],
        'hotel_guests' => [
            'module' => 'hotel',
            'label' => 'Guests',
            'description' => 'The guest list. Every reservation, invoice and loyalty entry of a deleted guest goes with them.',
            'roots' => ['guests'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
            ],
        ],
        'hotel_corporate_accounts' => [
            'module' => 'hotel',
            'label' => 'Corporate accounts',
            'description' => 'Corporate account records. Reservations and payments that used them are kept, unlinked.',
            'roots' => ['corporate_accounts'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
            ],
        ],
        'hotel_ops_tasks' => [
            'module' => 'hotel',
            'label' => 'Housekeeping & maintenance',
            'description' => 'Housekeeping tasks and maintenance issues.',
            'roots' => ['housekeeping_tasks', 'maintenance_issues'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
            ],
        ],
        'hotel_logs' => [
            'module' => 'hotel',
            'label' => 'Visitor logs, night audits & notifications',
            'description' => 'Operational logs and sent notifications.',
            'roots' => ['visitor_logs', 'night_audits', 'notifications'],
            'filters' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'column' => [
                    'visitor_logs' => 'time_in',
                    'night_audits' => 'business_date',
                    'notifications' => 'created_at',
                ]],
            ],
        ],
        'hotel_rooms' => [
            'module' => 'hotel',
            'label' => 'Rooms, room types & seasonal rates',
            'description' => 'The room catalogue. Stays in these rooms are deleted too.',
            'master' => true,
            'roots' => ['rooms', 'room_types', 'seasonal_rates'],
            'filters' => [],
        ],
        'hotel_packages' => [
            'module' => 'hotel',
            'label' => 'Packages',
            'description' => 'Stay packages. Reservations that used them are kept, unlinked.',
            'master' => true,
            'roots' => ['packages'],
            'filters' => [],
        ],
        'hotel_venues' => [
            'module' => 'hotel',
            'label' => 'Venues',
            'description' => 'Event venues. Their bookings are deleted too.',
            'master' => true,
            'roots' => ['venues'],
            'filters' => [],
        ],
        'hotel_laundry_items' => [
            'module' => 'hotel',
            'label' => 'Laundry price list',
            'description' => 'Laundry items and prices. Charges already posted to invoices are kept.',
            'master' => true,
            'roots' => ['laundry_items'],
            'filters' => [],
        ],

        // ── Restaurant ─────────────────────────────────────────────────────
        'restaurant_orders' => [
            'module' => 'restaurant',
            'label' => 'Orders & bills',
            'description' => 'Restaurant orders with their items, payments and loyalty earnings. Charges posted to hotel bills are removed from those bills.',
            'roots' => ['orders'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'lookup', 'column' => 'order_status_id', 'lookup' => 'order_status'],
            ],
        ],
        'restaurant_menu' => [
            'module' => 'restaurant',
            'label' => 'Menu (categories, items, add-ons)',
            'description' => 'The whole menu. Every order that contains a deleted item is deleted too.',
            'master' => true,
            'roots' => ['pos_menu_categories', 'pos_menu_items', 'add_ons'],
            'filters' => [],
        ],
        'restaurant_tables' => [
            'module' => 'restaurant',
            'label' => 'Dining areas & tables',
            'description' => 'Dining areas, tables and their QR codes. Orders are kept, unlinked from the table.',
            'master' => true,
            'roots' => ['dining_areas', 'dining_tables'],
            'filters' => [],
        ],

        // ── Inventory ──────────────────────────────────────────────────────
        'inventory_stock_history' => [
            'module' => 'inventory',
            'label' => 'Goods received & stock movements',
            'description' => 'GRNs and the stock movement history. On-hand quantities are not changed.',
            'roots' => ['grns', 'stock_movements'],
            'filters' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'column' => [
                    'grns' => 'received_at',
                    'stock_movements' => 'created_at',
                ]],
            ],
        ],
        'inventory_stock_levels' => [
            'module' => 'inventory',
            'label' => 'Reset on-hand stock to zero',
            'description' => 'Deletes all stock batches and sets every product and ingredient quantity to zero.',
            'roots' => ['ingredient_batches'],
            'reconcile' => ['zero_stock'],
            'filters' => [],
        ],
        'inventory_items' => [
            'module' => 'inventory',
            'label' => 'Products & ingredients',
            'description' => 'The item list with batches, recipes and stock history. GRNs that received them are deleted.',
            'master' => true,
            'roots' => ['ingredients'],
            'filters' => [
                ['key' => 'kind', 'label' => 'Kind', 'type' => 'lookup', 'column' => 'inventory_kind_id', 'lookup' => 'inventory_kind'],
            ],
        ],

        // ── Apartments ─────────────────────────────────────────────────────
        'apartment_bookings' => [
            'module' => 'apartments',
            'label' => 'Apartment bookings & invoices',
            'description' => 'Short-stay bookings with their invoice, lines and payments.',
            'roots' => ['apartment_bookings'],
            'filters' => [
                ['key' => 'check_in', 'label' => 'Check-in date', 'type' => 'date', 'column' => 'check_in'],
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'lookup', 'column' => 'booking_status_id', 'lookup' => 'apartment_booking_status'],
            ],
        ],
        'apartment_leases' => [
            'module' => 'apartments',
            'label' => 'Leases & tenant billing',
            'description' => 'Leases with rent charges, utility readings, invoice, lines and payments.',
            'roots' => ['apartment_leases'],
            'filters' => [
                ['key' => 'start', 'label' => 'Start date', 'type' => 'date', 'column' => 'start_date'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'lookup', 'column' => 'lease_status_id', 'lookup' => 'apartment_lease_status'],
            ],
        ],
        'apartment_sales' => [
            'module' => 'apartments',
            'label' => 'Unit sales',
            'description' => 'Sale records with their invoice, lines and payments.',
            'roots' => ['apartment_sales'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'lookup', 'column' => 'sale_status_id', 'lookup' => 'apartment_sale_status'],
            ],
        ],
        'apartment_customers' => [
            'module' => 'apartments',
            'label' => 'Apartment customers',
            'description' => 'The customer list. Every booking, lease and sale of a deleted customer goes with them.',
            'roots' => ['apartment_customers'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
            ],
        ],
        'apartment_ops_tasks' => [
            'module' => 'apartments',
            'label' => 'Apartment housekeeping & maintenance',
            'description' => 'Housekeeping tasks and maintenance issues for units.',
            'roots' => ['apartment_housekeeping_tasks', 'apartment_maintenance_issues'],
            'filters' => [
                ['key' => 'created', 'label' => 'Created date', 'type' => 'date', 'column' => 'created_at'],
            ],
        ],
        'apartment_catalog' => [
            'module' => 'apartments',
            'label' => 'Properties, unit types & units',
            'description' => 'The apartment catalogue. Bookings, leases and sales of these units are deleted too.',
            'master' => true,
            'roots' => ['apartment_properties', 'apartment_unit_types', 'apartment_units'],
            'filters' => [],
        ],

        // ── Payroll ────────────────────────────────────────────────────────
        'payroll_runs' => [
            'module' => 'payroll',
            'label' => 'Payroll runs & payslips',
            'description' => 'Pay runs with their payslip lines.',
            'roots' => ['payroll_runs'],
            'filters' => [
                ['key' => 'month', 'label' => 'Pay month', 'type' => 'month', 'column' => 'month'],
            ],
        ],
        'staff_attendance' => [
            'module' => 'payroll',
            'label' => 'Staff attendance',
            'description' => 'Clock-in / clock-out records.',
            'roots' => ['attendances'],
            'filters' => [
                ['key' => 'clock_in', 'label' => 'Clock-in date', 'type' => 'date', 'column' => 'clock_in'],
            ],
        ],

        // ── Till ───────────────────────────────────────────────────────────
        'till_sessions' => [
            'module' => 'till',
            'label' => 'Till sessions & cash movements',
            'description' => 'Cash drawer sessions with every cash in/out movement. The tills themselves are kept.',
            'roots' => ['till_sessions'],
            'filters' => [
                ['key' => 'opened', 'label' => 'Opened date', 'type' => 'date', 'column' => 'opened_at'],
            ],
        ],

        // ── Tenant Reset ────────────────────────────────────────────────────
        'tenant_reset' => [
            'module' => 'reset',
            'label' => 'Full tenant reset',
            'description' => 'Delete reservations, guests, POS orders, tables, menu, inventory, till records, QR codes, and reset rooms to available. Tills will open with zero balance tomorrow.',
            'master' => true,
            'roots' => [
                'reservations',
                'guests',
                'orders',
                'dining_areas',
                'dining_tables',
                'pos_menu_categories',
                'pos_menu_items',
                'add_ons',
                'ingredient_batches',
                'till_sessions',
                'qr_ordering_points',
            ],
            'reconcile' => ['reset_rooms', 'reset_till'],
            'filters' => [],
        ],
    ],

    /*
    | FKs that the schema declares SET NULL (or RESTRICT) but that mean
    | "belongs to". Keyed "child_table.column".
    */

    'edge_overrides' => [
        'folios.reservation_id' => 'cascade',
        'folios.venue_booking_id' => 'cascade',
        'folio_lines.order_id' => 'cascade',
        'payments.folio_id' => 'cascade',
        'payments.order_id' => 'cascade',
        'orders.reservation_id' => 'cascade',
        'apartment_ledgers.booking_id' => 'cascade',
        'apartment_ledgers.lease_id' => 'cascade',
        'apartment_ledgers.sale_id' => 'cascade',
        'apartment_payments.ledger_id' => 'cascade',
    ],

    /*
    | When a row of `child` is pulled into a purge through ANY parent, the row
    | its `column` points at is pulled in as well — otherwise the tenant is
    | left with a fragment of a document.
    */

    'owners' => [
        ['child' => 'order_items', 'column' => 'order_id'],
        ['child' => 'reservation_rooms', 'column' => 'reservation_id'],
        ['child' => 'grn_lines', 'column' => 'grn_id'],
    ],

    /*
    | Polymorphic references (no FK). `policy` applies to the child row when the
    | row it points at is purged: cascade = delete it, nullify = keep it and
    | clear both columns. Till movements are the cash ledger, so they are kept.
    */

    'loose_edges' => [
        [
            'child' => 'till_movements',
            'type_column' => 'source_type',
            'id_column' => 'source_id',
            'policy' => 'nullify',
            'targets' => [
                'App\\Models\\Hotel\\Payment' => 'payments',
                'App\\Models\\Apartment\\Payment' => 'apartment_payments',
            ],
        ],
        [
            'child' => 'loyalty_transactions',
            'type_column' => 'ref_type',
            'id_column' => 'ref_id',
            'policy' => 'cascade',
            'targets' => ['FOLIO' => 'folios', 'ORDER' => 'orders', 'VENUE' => 'venue_bookings'],
        ],
        [
            'child' => 'notifications',
            'type_column' => 'ref_type',
            'id_column' => 'ref_id',
            'policy' => 'cascade',
            'targets' => ['RESERVATION' => 'reservations', 'VENUE_BOOKING' => 'venue_bookings'],
        ],
    ],

    /*
    | Shown as a warning when rows of `table` that hang off `parent` (via
    | `column`) are deleted while the parent itself is kept.
    */

    'detached_warnings' => [
        ['table' => 'folio_lines', 'column' => 'folio_id', 'parent' => 'folios', 'message' => ':rows charge line(s) will be removed from :parents invoice(s) that are kept (for example restaurant charges posted to a room bill).'],
        ['table' => 'payments', 'column' => 'folio_id', 'parent' => 'folios', 'message' => ':rows payment(s) will be removed from :parents invoice(s) that are kept.'],
        ['table' => 'apartment_payments', 'column' => 'ledger_id', 'parent' => 'apartment_ledgers', 'message' => ':rows payment(s) will be removed from :parents apartment invoice(s) that are kept.'],
    ],

    /*
    | Unique per-tenant document codes. `prefixed` codes look like INV-2026-0012
    | (series prefix + running number); `numeric` ones are a bare integer.
    | `floor` => false keeps a series out of "continue numbering" because its
    | generator does not read the floors table.
    */

    'document_numbers' => [
        ['table' => 'reservations', 'column' => 'code', 'kind' => 'prefixed'],
        ['table' => 'group_bookings', 'column' => 'reference', 'kind' => 'prefixed'],
        ['table' => 'folios', 'column' => 'invoice_no', 'kind' => 'prefixed'],
        ['table' => 'venue_bookings', 'column' => 'code', 'kind' => 'prefixed'],
        ['table' => 'apartment_bookings', 'column' => 'code', 'kind' => 'prefixed'],
        ['table' => 'apartment_leases', 'column' => 'code', 'kind' => 'prefixed'],
        ['table' => 'apartment_sales', 'column' => 'code', 'kind' => 'prefixed'],
        ['table' => 'apartment_ledgers', 'column' => 'invoice_no', 'kind' => 'prefixed'],
        ['table' => 'grns', 'column' => 'grn_no', 'kind' => 'prefixed'],
        ['table' => 'pos_menu_items', 'column' => 'item_no', 'kind' => 'numeric', 'floor' => false],
    ],

    /*
    | Friendly names for the tables an operator sees in a preview.
    */

    'labels' => [
        'reservations' => 'Reservations',
        'reservation_rooms' => 'Reservation rooms',
        'room_item_checks' => 'Room item checks',
        'folios' => 'Hotel invoices',
        'folio_lines' => 'Invoice charge lines',
        'payments' => 'Payments',
        'group_bookings' => 'Group bookings',
        'venue_bookings' => 'Venue bookings',
        'venue_extra_charges' => 'Venue extra charges',
        'guests' => 'Guests',
        'loyalty_transactions' => 'Loyalty entries',
        'corporate_accounts' => 'Corporate accounts',
        'housekeeping_tasks' => 'Housekeeping tasks',
        'maintenance_issues' => 'Maintenance issues',
        'visitor_logs' => 'Visitor logs',
        'night_audits' => 'Night audits',
        'notifications' => 'Notifications',
        'rooms' => 'Rooms',
        'room_types' => 'Room types',
        'seasonal_rates' => 'Seasonal rates',
        'packages' => 'Packages',
        'venues' => 'Venues',
        'laundry_items' => 'Laundry items',
        'orders' => 'Orders',
        'order_items' => 'Order items',
        'order_item_modifiers' => 'Order item modifiers',
        'pos_menu_categories' => 'Menu categories',
        'pos_menu_items' => 'Menu items',
        'menu_item_modifier_groups' => 'Modifier groups',
        'menu_item_modifiers' => 'Modifiers',
        'add_ons' => 'Add-ons',
        'add_on_links' => 'Add-on links',
        'recipe_items' => 'Recipe lines',
        'dining_areas' => 'Dining areas',
        'dining_tables' => 'Dining tables',
        'qr_ordering_points' => 'QR ordering points',
        'grns' => 'Goods received notes',
        'grn_lines' => 'GRN lines',
        'stock_movements' => 'Stock movements',
        'ingredients' => 'Products & ingredients',
        'ingredient_batches' => 'Stock batches',
        'apartment_bookings' => 'Apartment bookings',
        'apartment_leases' => 'Leases',
        'apartment_sales' => 'Unit sales',
        'apartment_customers' => 'Apartment customers',
        'apartment_ledgers' => 'Apartment invoices',
        'apartment_ledger_lines' => 'Apartment invoice lines',
        'apartment_payments' => 'Apartment payments',
        'apartment_lease_rent_charges' => 'Rent charges',
        'apartment_utility_readings' => 'Utility readings',
        'apartment_housekeeping_tasks' => 'Apartment housekeeping tasks',
        'apartment_maintenance_issues' => 'Apartment maintenance issues',
        'apartment_properties' => 'Properties',
        'apartment_unit_types' => 'Unit types',
        'apartment_units' => 'Units',
        'apartment_seasonal_rates' => 'Apartment seasonal rates',
        'payroll_runs' => 'Payroll runs',
        'payroll_lines' => 'Payslip lines',
        'attendances' => 'Attendance records',
        'till_sessions' => 'Till sessions',
        'till_movements' => 'Till cash movements',
    ],
];
