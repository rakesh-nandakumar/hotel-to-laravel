<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the tenant isolation column to the tenant-owned root tables
     * (Branch, User, Role, Setting — see App\Models\Concerns\BelongsToTenant).
     * Everything else inherits isolation transitively through its relation to
     * one of these. Existing rows (this app's live single-hotel data) are
     * backfilled onto one "Default Tenant" so nothing already in production
     * goes dark.
     *
     * Nullable rather than NOT NULL: this codebase's own precedent (orders.staff_id,
     * apartment_payments.staff_id) already uses nullable FKs enforced at the
     * application layer rather than a DB constraint when a "no owner yet" state
     * is legitimate — central admins simply never populate this column at all.
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
        });
        Schema::table('settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('key')->constrained('tenants')->nullOnDelete();
        });

        $tenantId = DB::table('tenants')->where('slug', 'default')->value('id');
        if (! $tenantId) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Default Tenant',
                'slug' => 'default',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('warehouses')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        DB::table('users')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        DB::table('roles')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
        DB::table('settings')->whereNull('tenant_id')->update(['tenant_id' => $tenantId]);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
