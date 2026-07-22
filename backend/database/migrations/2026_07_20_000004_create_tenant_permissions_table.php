<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defines which permissions are enabled for each tenant. This acts as the
 * upper limit — tenant users can only be granted permissions that appear in
 * this table for their tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_permissions', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['tenant_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_permissions');
    }
};
