<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deferred from Module 4 (Reservations/Folios) — a folio line or payment
     * can now originate from a POS order (`postOrderToFolio`, `Order::settle`).
     * Lines/payments tagged with an order_id were already taxed at order time
     * and must never be re-taxed at folio checkout — see
     * ReservationService::checkout()/checkoutQuote() which now filter on this.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('folio_id')->constrained('orders')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('folio_id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
