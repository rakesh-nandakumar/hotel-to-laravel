<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Administrator-enforced two-factor: users with this flag always
            // face a second factor at login and cannot disable it themselves.
            $table->boolean('two_factor_required')->default(false)->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_required');
        });
    }
};
