<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apartments never had a cash-drawer concept before — the Till system is
     * the first, and it's shared with Hotel/Restaurant (same `tills` table,
     * scoped by branch_id).
     */
    public function up(): void
    {
        Schema::table('apartment_payments', function (Blueprint $table) {
            $table->foreignId('till_session_id')->nullable()->after('staff_id')->constrained('till_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apartment_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('till_session_id');
        });
    }
};
