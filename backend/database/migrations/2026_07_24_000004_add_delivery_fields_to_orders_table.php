<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_address')->nullable()->after('dining_table_id');
            $table->string('delivery_phone')->nullable()->after('delivery_address');
            $table->foreignId('delivery_rider_id')->nullable()->after('delivery_phone')->constrained('users')->nullOnDelete();
            $table->foreignId('delivery_status_id')->nullable()->after('delivery_rider_id')->constrained('lookups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_rider_id');
            $table->dropConstrainedForeignId('delivery_status_id');
            $table->dropColumn(['delivery_address', 'delivery_phone']);
        });
    }
};
