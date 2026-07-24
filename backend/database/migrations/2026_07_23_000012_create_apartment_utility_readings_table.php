<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_utility_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained('apartment_leases')->cascadeOnDelete();
            $table->foreignId('utility_type_id')->constrained('lookups')->restrictOnDelete();
            $table->date('period_month')->comment('First-of-month date identifying the billing period');
            $table->decimal('previous_reading', 12, 2);
            $table->decimal('current_reading', 12, 2);
            $table->unsignedInteger('rate_per_unit')->comment('LKR cents per meter unit');
            $table->unsignedInteger('amount')->comment('LKR cents — (current - previous) * rate_per_unit');
            $table->foreignId('ledger_line_id')->nullable()->constrained('apartment_ledger_lines')->nullOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['lease_id', 'utility_type_id', 'period_month'], 'apartment_utility_readings_unique_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_utility_readings');
    }
};
