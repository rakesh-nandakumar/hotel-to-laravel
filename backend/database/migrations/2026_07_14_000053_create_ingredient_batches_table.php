<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Food expiry tracking: stock received in batches with expiry dates.
     * Deductions drain batches FEFO (first-expiring-first-out); batch levels
     * are informational — `ingredients.stock_qty` stays the authoritative total.
     */
    public function up(): void
    {
        Schema::create('ingredient_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('qty', 12, 3)->comment('remaining');
            $table->decimal('initial_qty', 12, 3);
            $table->date('expiry_date')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['ingredient_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_batches');
    }
};
