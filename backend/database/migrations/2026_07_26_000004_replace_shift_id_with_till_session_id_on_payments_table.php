<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Till system replaces the old staff-only Shift cash-drawer — every
     * payment now attaches to the till session open at the time (any method,
     * not just cash) instead of a shift. See BillingService::recordPayment().
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('till_session_id')->nullable()->after('staff_id')->constrained('till_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('till_session_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('staff_id')->constrained('shifts')->nullOnDelete();
        });
    }
};
