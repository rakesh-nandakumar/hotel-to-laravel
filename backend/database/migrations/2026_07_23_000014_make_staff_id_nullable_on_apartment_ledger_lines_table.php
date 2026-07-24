<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring rent/utility charges are posted by the scheduled billing job
     * (ApartmentLeaseBillingService), not an authenticated staff member —
     * null here means "posted by the system", mirroring the nullable
     * `night_audits.run_by_id` precedent for the same distinction.
     */
    public function up(): void
    {
        Schema::table('apartment_ledger_lines', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('apartment_ledger_lines', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable(false)->change();
        });
    }
};
