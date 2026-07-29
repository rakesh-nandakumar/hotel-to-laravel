<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QR-placed guest orders (see qr_ordering_points) have no authenticated
     * staff member at creation time — null here means "placed by the guest,
     * not staff", the same system-posted distinction already established for
     * apartment_payments.staff_id / apartment_ledger_lines.staff_id.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable(false)->change();
        });
    }
};
