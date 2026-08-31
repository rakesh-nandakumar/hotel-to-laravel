<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills `must_change_password` on environments where
 * 2026_06_11_050846_add_password_change_tracking_to_users_table ran before
 * that column was added to it — the migrations table marked it done, so
 * `migrate` never revisits it, leaving `password_changed_at` present but
 * `must_change_password` missing and every password write erroring with
 * "Unknown column 'must_change_password'".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('locked_until');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
