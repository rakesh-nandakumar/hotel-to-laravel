<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `roles.name` and `users.email` were globally unique — correct for a
     * single business, wrong once unrelated tenants share these tables: two
     * different hotel companies independently naming a role "Manager", or a
     * staff member's email colliding with an unrelated tenant's, must not
     * collide. Scope both uniques to (tenant_id, column) instead.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique(['email']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['name']);
        });
    }
};
