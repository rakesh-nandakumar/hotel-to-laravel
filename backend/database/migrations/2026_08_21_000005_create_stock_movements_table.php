<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The consumption ledger: every batch draw and every return is a row,
     * signed (positive in, negative out), carrying the batch's `unit_cost` at
     * the time it was drawn — this is what gives a true cost basis per unit
     * sold, and what lets a void restock the exact batches it drew from.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('ingredient_batch_id')->nullable()->constrained('ingredient_batches')->nullOnDelete();
            $table->foreignId('movement_type_id')->constrained('lookups');
            $table->decimal('qty', 12, 3)->comment('signed: positive in, negative out');
            $table->integer('unit_cost')->nullable()->comment('cents — snapshot of the batch cost consumed');
            $table->string('reference_type')->nullable()->comment('order_item | grn_line | adjustment | write_off');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ingredient_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
