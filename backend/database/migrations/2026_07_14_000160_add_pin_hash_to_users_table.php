<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-assigned quick-unlock PIN for POS terminals (4-6 digits, hashed).
     * Only usable together with a device token — see device_tokens.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_hash');
        });
    }
};
