<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One open->close cycle of a physical Till by an operator — the
     * replacement for the old staff-only `shifts` table, now branch/till
     * scoped so Apartments can share the same cash-drawer concept as Hotel.
     * Column names deliberately match `shifts` (opening_cash/closing_cash/
     * expected_cash/variance/opened_at/closed_at) so the daily-report and
     * per-shift-sales queries barely change.
     */
    public function up(): void
    {
        Schema::create('till_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('till_id')->constrained('tills')->restrictOnDelete();
            $table->foreignId('status_id')->comment('till_session_status lookup: open/closed')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('opening_cash')->comment('LKR cents counted at open');
            $table->unsignedInteger('closing_cash')->nullable()->comment('counted at close');
            $table->integer('expected_cash')->nullable()->comment('sum of till_movements at close time');
            $table->integer('variance')->nullable()->comment('closing_cash - expected_cash');
            $table->text('notes')->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['till_id', 'status_id']);
            $table->index(['opened_by', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_sessions');
    }
};
