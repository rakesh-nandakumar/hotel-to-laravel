<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user may now hold many roles. The legacy single users.role_id is kept as
     * an optional "primary role" for display/redirect, but permission computation
     * reads every assigned role from this pivot.
     */
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'role_id']);
        });

        // Backfill from the existing single-role assignment.
        DB::table('users')
            ->whereNotNull('role_id')
            ->orderBy('id')
            ->get(['id', 'role_id'])
            ->each(function ($user) {
                DB::table('user_roles')->insertOrIgnore([
                    'user_id' => $user->id,
                    'role_id' => $user->role_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
