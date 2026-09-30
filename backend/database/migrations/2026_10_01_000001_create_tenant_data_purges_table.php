<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tenant data purge run from master control. Holds the pointer
     * to the private backup file that makes the purge restorable until
     * `expires_at`. Platform-level (central) data — never tenant-scoped.
     */
    public function up(): void
    {
        Schema::create('tenant_data_purges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('central_admin_id')->nullable()->constrained('central_admins')->nullOnDelete();
            $table->string('status', 20)->default('completed')->comment('completed | restored | expired');
            $table->json('selections');
            $table->json('summary')->comment('per-table row counts, warnings, reconciliations');
            $table->string('fingerprint', 64);
            $table->text('note')->nullable();
            $table->boolean('continue_numbering')->default(false);
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->string('backup_path')->nullable();
            $table->unsignedBigInteger('backup_bytes')->default(0);
            $table->string('backup_sha256', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('central_admins')->nullOnDelete();
            $table->json('restore_summary')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_data_purges');
    }
};
