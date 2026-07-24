<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per lease per billed month — the idempotency guard for
     * ApartmentLeaseBillingService::generateMonthlyCharges() so the
     * scheduled job can safely run twice for the same month (e.g. a retry
     * after a crash) without ever double-charging rent.
     */
    public function up(): void
    {
        Schema::create('apartment_lease_rent_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('apartment_leases')->cascadeOnDelete();
            $table->date('period_month')->comment('First-of-month date identifying the billing period');
            $table->foreignId('ledger_line_id')->constrained('apartment_ledger_lines')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lease_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_lease_rent_charges');
    }
};
