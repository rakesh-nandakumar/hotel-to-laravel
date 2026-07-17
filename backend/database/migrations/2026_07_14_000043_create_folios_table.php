<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_type_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('folio_status_id')->constrained('lookups')->restrictOnDelete();
            $table->string('invoice_no')->nullable()->unique()->comment('assigned at settlement, e.g. INV-2026-0012');
            $table->foreignId('reservation_id')->nullable()->unique()->constrained('reservations')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
