<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No FK link to the `attendances` rows worked_hours is aggregated from —
     * matches Node exactly (a real schema gap, not fixed here — see
     * phase2-nodejs-schema memory edge case #6).
     */
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('base_salary')->comment('snapshot at generation');
            $table->decimal('worked_hours', 8, 2)->default(0);
            $table->decimal('ot_hours', 8, 2)->default(0)->comment('auto: hours beyond standard, editable pre-finalize');
            $table->unsignedInteger('ot_pay')->default(0);
            $table->unsignedInteger('allowance')->default(0);
            $table->unsignedInteger('bonus')->default(0);
            $table->unsignedInteger('deduction')->default(0)->comment('advances, no-pay etc.');
            $table->string('deduction_note')->nullable();
            $table->unsignedInteger('gross')->default(0);
            $table->unsignedInteger('epf_employee')->default(0)->comment('deducted from employee');
            $table->unsignedInteger('epf_employer')->default(0)->comment('employer contribution, not deducted');
            $table->unsignedInteger('etf')->default(0)->comment('employer contribution');
            $table->unsignedInteger('net_pay')->default(0);
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
