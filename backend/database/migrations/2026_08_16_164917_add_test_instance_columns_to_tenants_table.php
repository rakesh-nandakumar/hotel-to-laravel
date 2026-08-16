<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Test instances (cloned tenant workspaces) — see TestInstanceService.
     * environment + parent_tenant_id turn a live tenant into a full clone,
     * and last_synced_* records the most recent master-control sync.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('environment', 10)->default('live')->index()->after('status');
            $table->foreignId('parent_tenant_id')
                ->nullable()
                ->after('environment')
                ->constrained('tenants')
                ->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable()->after('parent_tenant_id');
            $table->foreignId('last_synced_by')
                ->nullable()
                ->after('last_synced_at')
                ->constrained('central_admins')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_synced_by');
            $table->dropConstrainedForeignId('parent_tenant_id');
            $table->dropColumn(['environment', 'last_synced_at']);
        });
    }
};
