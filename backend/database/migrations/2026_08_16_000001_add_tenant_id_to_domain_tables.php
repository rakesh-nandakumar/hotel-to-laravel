<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends tenant row-level isolation (App\Models\Concerns\BelongsToTenant)
 * from the four core tables (branches, users, roles, settings) to every
 * business-domain table. Until now guests, customers, reservations, orders,
 * menu items and the rest carried no tenant column and no scope, so any
 * tenant user could see every tenant's data.
 *
 * Backfill strategy, safest-first:
 *   1. Parent-chain backfill — a row inherits its tenant from the tenant-owned
 *      or branch-owned row it hangs off (reservation → guest, order → room,
 *      unit → property → branch, …).
 *   2. Default-tenant fallback — rows with no attributable owner (pre-tenancy
 *      production data, exactly like the core migration's backfill) go to the
 *      "default" tenant when one exists, else to the sole tenant, else stay
 *      NULL (invisible to tenant users — never mis-attributed).
 *   3. audit_logs is the deliberate exception: it never falls back, because
 *      central/system records (no tenant) must remain invisible to tenants.
 *
 * Single-column unique constraints are also re-scoped to (tenant_id, column)
 * — or (branch_id, column) for the branch-scoped tables — so two tenants can
 * both have "Room 101" or a "Standard" room type. Identity-style keys that
 * must stay globally unique (device token hashes, QR tokens) are left alone.
 */
return new class extends Migration
{
    /**
     * Tables whose rows have no tenant-attributable parent — they get the
     * default-tenant fallback directly.
     *
     * @var list<string>
     */
    private const FALLBACK_TABLES = [
        'guests',
        'corporate_accounts',
        'group_bookings',
        'room_types',
        'packages',
        'pos_menu_categories',
        'pos_menu_items',
        'ingredients',
        'dining_areas',
        'dining_tables',
        'laundry_items',
        'visitor_logs',
        'notifications',
        'night_audits',
        'add_ons',
        'payroll_runs',
        'apartment_customers',
        'apartment_unit_types',
    ];

    /**
     * Tables whose rows inherit their tenant from a parent row via a join —
     * {table} => [parent_table => fk_column], in dependency order (parents
     * backfilled before children).
     *
     * @var array<string, array<string, string>>
     */
    private const PARENT_BACKFILLS = [
        // Branch-scoped tables inherit their tenant from the branch itself.
        'rooms' => ['warehouses' => 'branch_id'],
        'venues' => ['warehouses' => 'branch_id'],
        'apartment_properties' => ['warehouses' => 'branch_id'],
        'tills' => ['warehouses' => 'branch_id'],
        'loyalty_transactions' => ['guests' => 'guest_id'],
        'reservations' => ['guests' => 'guest_id'],
        'reservation_rooms' => ['reservations' => 'reservation_id'],
        'folios' => ['reservations' => 'reservation_id'],
        'folio_lines' => ['folios' => 'folio_id'],
        'payments' => ['folios' => 'folio_id'],
        'room_item_checks' => ['reservations' => 'reservation_id'],
        'seasonal_rates' => ['room_types' => 'room_type_id'],
        'ingredient_batches' => ['ingredients' => 'ingredient_id'],
        'recipe_items' => ['pos_menu_items' => 'menu_item_id'],
        'menu_item_modifier_groups' => ['pos_menu_items' => 'menu_item_id'],
        'menu_item_modifiers' => ['menu_item_modifier_groups' => 'modifier_group_id'],
        'order_items' => ['orders' => 'order_id'],
        'order_item_modifiers' => ['order_items' => 'order_item_id'],
        'orders' => ['rooms' => 'room_id'],
        'add_on_links' => ['add_ons' => 'add_on_id'],
        'housekeeping_tasks' => ['rooms' => 'room_id'],
        'maintenance_issues' => ['rooms' => 'room_id'],
        'venue_bookings' => ['venues' => 'venue_id'],
        'attendances' => ['users' => 'user_id'],
        'payroll_lines' => ['users' => 'user_id'],
        'qr_ordering_points' => ['rooms' => 'room_id'],
        'till_sessions' => ['tills' => 'till_id'],
        'till_movements' => ['till_sessions' => 'till_session_id'],
        'audit_logs' => ['users' => 'actor_id'],
        'device_tokens' => ['users' => 'user_id'],
        'apartment_units' => ['apartment_properties' => 'property_id'],
        'apartment_seasonal_rates' => ['apartment_unit_types' => 'unit_type_id'],
        'apartment_bookings' => ['apartment_units' => 'unit_id'],
        'apartment_leases' => ['apartment_units' => 'unit_id'],
        'apartment_sales' => ['apartment_units' => 'unit_id'],
        'apartment_ledgers' => ['apartment_bookings' => 'booking_id'],
        'apartment_ledger_lines' => ['apartment_ledgers' => 'ledger_id'],
        'apartment_payments' => ['apartment_ledgers' => 'ledger_id'],
        'apartment_utility_readings' => ['apartment_leases' => 'lease_id'],
        'apartment_lease_rent_charges' => ['apartment_leases' => 'lease_id'],
        'apartment_housekeeping_tasks' => ['apartment_units' => 'unit_id'],
        'apartment_maintenance_issues' => ['apartment_units' => 'unit_id'],
    ];

    /**
     * Tables already carrying a tenant_id column (no column added here).
     *
     * @var list<string>
     */
    private const ALREADY_SCOPED_TABLES = [
        'users', 'roles', 'warehouses', 'settings', 'tenant_modules', 'impersonation_tokens',
    ];

    /**
     * Single-column unique keys that must become tenant-scoped —
     * [table => [old_index => new_columns]].
     *
     * @var array<string, array<string, list<string>>>
     */
    private const UNIQUE_REWRITES = [
        'room_types' => ['room_types_name_unique' => ['tenant_id', 'name']],
        'packages' => ['packages_code_unique' => ['tenant_id', 'code']],
        'group_bookings' => ['group_bookings_reference_unique' => ['tenant_id', 'reference']],
        'reservations' => ['reservations_code_unique' => ['tenant_id', 'code']],
        'folios' => ['folios_invoice_no_unique' => ['tenant_id', 'invoice_no']],
        'pos_menu_categories' => ['pos_menu_categories_name_unique' => ['tenant_id', 'name']],
        'pos_menu_items' => ['pos_menu_items_item_no_unique' => ['tenant_id', 'item_no']],
        'ingredients' => ['ingredients_name_unique' => ['tenant_id', 'name']],
        'venue_bookings' => ['venue_bookings_code_unique' => ['tenant_id', 'code']],
        'laundry_items' => ['laundry_items_name_unique' => ['tenant_id', 'name']],
        'payroll_runs' => ['payroll_runs_month_unique' => ['tenant_id', 'month']],
        'night_audits' => ['night_audits_business_date_unique' => ['tenant_id', 'business_date']],
        'apartment_unit_types' => ['apartment_unit_types_name_unique' => ['tenant_id', 'name']],
        'apartment_units' => ['apartment_units_unit_no_unique' => ['tenant_id', 'unit_no']],
        'apartment_bookings' => ['apartment_bookings_code_unique' => ['tenant_id', 'code']],
        'apartment_ledgers' => ['apartment_ledgers_invoice_no_unique' => ['tenant_id', 'invoice_no']],
        'apartment_payments' => ['apartment_payments_idempotency_key_unique' => ['tenant_id', 'idempotency_key']],
        'apartment_leases' => ['apartment_leases_code_unique' => ['tenant_id', 'code']],
        'apartment_sales' => ['apartment_sales_code_unique' => ['tenant_id', 'code']],
        'dining_areas' => ['dining_areas_name_unique' => ['tenant_id', 'name']],
        'dining_tables' => ['dining_tables_table_no_unique' => ['tenant_id', 'table_no']],
        'orders' => ['orders_client_key_unique' => ['tenant_id', 'client_key']],
        'payments' => ['payments_idempotency_key_unique' => ['tenant_id', 'idempotency_key']],
        // Branch-scoped tables — the tenant uniqueness derives from the branch.
        'rooms' => ['rooms_number_unique' => ['branch_id', 'number']],
        'venues' => ['venues_name_unique' => ['branch_id', 'name']],
        'apartment_properties' => ['apartment_properties_name_unique' => ['branch_id', 'name']],
    ];

    public function up(): void
    {
        $tables = array_values(array_unique(array_merge(
            array_keys(self::PARENT_BACKFILLS),
            self::FALLBACK_TABLES,
        )));

        foreach ($tables as $table) {
            $this->addTenantColumn($table);
        }

        $this->backfillFromParents();
        $this->backfillFallback();

        foreach (self::UNIQUE_REWRITES as $table => $rewrites) {
            foreach ($rewrites as $oldIndex => $columns) {
                Schema::table($table, function (Blueprint $blueprint) use ($oldIndex, $columns) {
                    $blueprint->dropUnique($oldIndex);
                    $blueprint->unique($columns);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::UNIQUE_REWRITES as $table => $rewrites) {
            foreach ($rewrites as $oldIndex => $columns) {
                Schema::table($table, function (Blueprint $blueprint) use ($oldIndex, $columns) {
                    $blueprint->dropUnique($this->indexName($table, $columns));
                    $blueprint->unique($this->oldUniqueColumn($oldIndex));
                });
            }
        }

        $tables = array_values(array_unique(array_merge(
            array_keys(self::PARENT_BACKFILLS),
            self::FALLBACK_TABLES,
        )));
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('tenant_id');
            });
        }
    }

    private function addTenantColumn(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->nullOnDelete();
        });
    }

    /**
     * Step 1: rows inherit their tenant from the parent row they belong to.
     * Each UPDATE only touches rows still unowned, so a chain (order → room →
     * branch) converges as its parents get filled in.
     *
     * Written as correlated subqueries rather than UPDATE ... JOIN so the
     * migration runs on both MySQL (production) and SQLite (tests) — SQLite
     * does not support the JOIN form.
     */
    private function backfillFromParents(): void
    {
        foreach (self::PARENT_BACKFILLS as $child => $parents) {
            foreach ($parents as $parent => $fk) {
                DB::statement(
                    "UPDATE {$child}
                     SET tenant_id = (SELECT p.tenant_id FROM {$parent} p WHERE p.id = {$child}.{$fk})
                     WHERE tenant_id IS NULL
                       AND EXISTS (SELECT 1 FROM {$parent} p WHERE p.id = {$child}.{$fk} AND p.tenant_id IS NOT NULL)"
                );
            }
        }
    }

    /**
     * Step 2: anything still unowned (pre-tenancy data with no attributable
     * parent) goes to the "default" tenant when one exists, else to the sole
     * tenant, else stays NULL. audit_logs never participates — central/system
     * records have no tenant and must stay invisible to tenant users.
     */
    private function backfillFallback(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'default')->value('id')
            ?? (DB::table('tenants')->count() === 1 ? DB::table('tenants')->value('id') : null);

        if ($tenantId === null) {
            return;
        }

        foreach (self::FALLBACK_TABLES as $table) {
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        }

        // Child tables whose parent chain resolved nothing still deserve the
        // default tenant rather than being hidden — except audit_logs.
        $childTables = array_diff(array_keys(self::PARENT_BACKFILLS), ['audit_logs']);
        foreach ($childTables as $table) {
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        }
    }

    private function indexName(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_unique';
    }

    private function oldUniqueColumn(string $oldIndex): string
    {
        // The original single-column name is the index name minus the
        // "{table}_" prefix and "_unique" suffix.
        $withoutTable = str_replace('_unique', '', $oldIndex);

        return substr($withoutTable, strrpos($withoutTable, '_') + 1);
    }
};
