<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only cash ledger — the single source of truth for a till
     * session's balance (TillService::expectedBalance() always sums this
     * table; it is never derived from sales reports). `source` links a
     * system-generated row back to the Hotel/Apartment Payment that created
     * it; manual entries (cash withdrawals, float top-ups, expenses) leave it null.
     */
    public function up(): void
    {
        Schema::create('till_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('till_session_id')->constrained('till_sessions')->restrictOnDelete();
            $table->foreignId('type_id')->comment('till_movement_type lookup')->constrained('lookups')->restrictOnDelete();
            $table->integer('amount')->comment('LKR cents, signed: positive = cash in, negative = cash out');
            $table->text('reason')->nullable()->comment('mandatory for cash_out/expense/transfer/refund — enforced in TillService');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['till_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_movements');
    }
};
