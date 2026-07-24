<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_status_id')->constrained('lookups')->restrictOnDelete();
            $table->string('invoice_no')->nullable()->unique()->comment('assigned at settlement, e.g. APT-INV-2026-0012');
            // lease_id / sale_id (M3/M4) join the same nullable-owner-FK pattern —
            // a ledger belongs to exactly one of booking/lease/sale.
            $table->foreignId('booking_id')->nullable()->unique()->constrained('apartment_bookings')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_ledgers');
    }
};
