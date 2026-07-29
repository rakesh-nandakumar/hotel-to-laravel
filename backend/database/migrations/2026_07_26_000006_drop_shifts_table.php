<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Superseded by till_sessions (see 2026_07_26_000002_create_till_sessions_table
     * and 2026_07_26_000004_replace_shift_id_with_till_session_id_on_payments_table).
     * No data is carried over — the two shapes differ materially (till_sessions
     * is branch/till scoped and reconciled against an actual movement ledger,
     * not a shift-level payment sum) and this app has no production shift history.
     */
    public function up(): void
    {
        Schema::dropIfExists('shifts');
    }

    public function down(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('opening_cash')->comment('LKR cents counted at open');
            $table->unsignedInteger('closing_cash')->nullable()->comment('counted at close');
            $table->integer('expected_cash')->nullable()->comment('opening + cash payments - cash refunds during shift');
            $table->integer('variance')->nullable()->comment('closing_cash - expected_cash');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
