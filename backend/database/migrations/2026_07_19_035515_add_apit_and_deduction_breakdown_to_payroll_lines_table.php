<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business formula change: gross now subtracts unpaid-leave deduction, EPF/ETF
 * move from a base-salary base to a gross base, APIT (Sri Lanka progressive
 * income tax) is introduced, the old flat `deduction` splits into
 * loan/advance/other_deduction, and employer_cost is persisted rather than
 * recomputed. `deduction`/`deduction_note` are RENAMED (not dropped) so
 * existing payroll history keeps its values under the new name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->renameColumn('deduction', 'other_deduction');
            $table->renameColumn('deduction_note', 'other_deduction_note');
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->unsignedInteger('unpaid_leave_deduction')->default(0)->after('bonus');
            $table->unsignedInteger('loan')->default(0)->after('other_deduction');
            $table->unsignedInteger('advance')->default(0)->after('loan');
            $table->unsignedInteger('apit')->default(0)->after('epf_employee')->comment('Sri Lanka APIT, deducted from employee');
            $table->unsignedInteger('employer_cost')->default(0)->after('net_pay')->comment('gross + epf_employer + etf, not deducted');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropColumn(['unpaid_leave_deduction', 'loan', 'advance', 'apit', 'employer_cost']);
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->renameColumn('other_deduction', 'deduction');
            $table->renameColumn('other_deduction_note', 'deduction_note');
        });
    }
};
