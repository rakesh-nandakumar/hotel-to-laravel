<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deferred from Module 4 — every payment recorded while the staff member
     * has an open shift attaches to it (any method, not just cash) for
     * drawer reconciliation/reporting; see BillingService::recordPayment().
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('staff_id')->constrained('shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
