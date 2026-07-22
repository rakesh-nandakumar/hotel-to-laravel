<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a tenant_id column to every application table for multi-tenant data
 * isolation. A default tenant is created and all existing rows are assigned
 * to it so the migration is non-destructive.
 *
 * Infrastructure tables (sessions, cache, jobs, password_reset_tokens,
 * personal_access_tokens, passkeys) are deliberately excluded — they are
 * framework/session-scoped, not business-data tables.
 */
return new class extends Migration
{
    /**
     * Tables that need a tenant_id foreign key. Ordered so that parent tables
     * come before children (FK constraint safety during rollback).
     *
     * @var list<string>
     */
    private const TENANT_TABLES = [
        'users',
        'roles',
        'settings',
        'menu_items',
        'audit_logs',
        'device_tokens',
        'room_types',
        'rooms',
        'seasonal_rates',
        'packages',
        'guests',
        'loyalty_transactions',
        'corporate_accounts',
        'group_bookings',
        'reservations',
        'reservation_rooms',
        'folios',
        'folio_lines',
        'payments',
        'room_item_checks',
        'housekeeping_tasks',
        'pos_menu_categories',
        'pos_menu_items',
        'ingredients',
        'ingredient_batches',
        'recipe_items',
        'orders',
        'order_items',
        'maintenance_issues',
        'laundry_items',
        'venues',
        'venue_bookings',
        'shifts',
        'attendances',
        'payroll_runs',
        'payroll_lines',
        'visitor_logs',
        'notifications',
        'night_audits',
    ];

    public function up(): void
    {
        // Create a default tenant for existing data.
        $defaultTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Default Hotel',
            'slug' => 'default',
            'email' => 'admin@vellix.com',
            'status' => 'active',
            'plan' => 'standard',
            'storage_limit_mb' => 5120,
            'max_users' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TENANT_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                } else {
                    $table->unsignedBigInteger('tenant_id')->nullable();
                }
            });

            // Assign all existing rows to the default tenant.
            DB::table($tableName)->whereNull('tenant_id')->update(['tenant_id' => $defaultTenantId]);

            // Now make the column non-nullable and add the foreign key.
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index('tenant_id', "{$tableName}_tenant_id_index");

                if ($tableName === 'settings') {
                    $table->dropPrimary();
                    $table->primary(['tenant_id', 'key']);
                }

                $uniqueColumns = [
                    'users' => ['email'],
                    'roles' => ['name'],
                    'room_types' => ['name'],
                    'rooms' => ['number'],
                    'packages' => ['code'],
                    'group_bookings' => ['reference'],
                    'reservations' => ['code'],
                    'pos_menu_categories' => ['name'],
                    'pos_menu_items' => ['item_no'],
                    'ingredients' => ['name'],
                    'laundry_items' => ['name'],
                    'venues' => ['name'],
                    'venue_bookings' => ['code'],
                    'payroll_runs' => ['month'],
                    'night_audits' => ['business_date'],
                ];

                if (isset($uniqueColumns[$tableName])) {
                    foreach ($uniqueColumns[$tableName] as $column) {
                        $table->dropUnique("{$tableName}_{$column}_unique");
                        $table->unique(['tenant_id', $column], "{$tableName}_{$column}_tenant_unique");
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropForeign(["{$tableName}_tenant_id_foreign"]);
                $table->dropIndex("{$tableName}_tenant_id_index");
                $table->dropColumn('tenant_id');
            });
        }

        // Remove the default tenant created during up().
        DB::table('tenants')->where('slug', 'default')->delete();
    }
};
