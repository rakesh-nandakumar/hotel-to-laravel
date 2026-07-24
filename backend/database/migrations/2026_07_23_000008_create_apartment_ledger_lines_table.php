<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained('apartment_ledgers')->cascadeOnDelete();
            $table->foreignId('line_source_id')->constrained('lookups')->restrictOnDelete();
            $table->string('description');
            $table->decimal('qty', 10, 2)->default(1);
            $table->integer('unit_price')->comment('LKR cents — negative for discounts');
            $table->integer('amount')->comment('qty * unit_price, LKR cents — negative for discounts');
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->boolean('voided')->default(false);
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index('ledger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_ledger_lines');
    }
};
