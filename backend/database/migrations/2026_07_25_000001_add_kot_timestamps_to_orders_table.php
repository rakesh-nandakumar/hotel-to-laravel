<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('kot_started_at')->nullable()->after('kot_status_id');
            $table->timestamp('kot_ready_at')->nullable()->after('kot_started_at');
            $table->timestamp('served_at')->nullable()->after('kot_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['kot_started_at', 'kot_ready_at', 'served_at']);
        });
    }
};
