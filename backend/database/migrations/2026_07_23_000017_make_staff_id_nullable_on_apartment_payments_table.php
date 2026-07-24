<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The expired sale-reservation-hold sweep (ApartmentSalesService::releaseExpiredHolds(),
     * scheduled — see routes/console.php) can trigger a refund payment with no
     * authenticated staff member — null here means "posted by the system",
     * same distinction as apartment_ledger_lines.staff_id.
     */
    public function up(): void
    {
        Schema::table('apartment_payments', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('apartment_payments', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable(false)->change();
        });
    }
};
