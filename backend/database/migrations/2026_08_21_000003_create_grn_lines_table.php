<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No unique on (grn_id, ingredient_id) — one GRN deliberately carries the
     * same item twice at different costs/expiries (e.g. Coke Batch A and
     * Batch B in the same delivery). Each line becomes exactly one
     * ingredient_batches row on receive.
     */
    public function up(): void
    {
        Schema::create('grn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('grn_id')->constrained('grns')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('qty', 12, 3);
            $table->integer('unit_cost')->comment('cents');
            $table->integer('line_total')->comment('cents');
            $table->string('batch_no')->nullable();
            $table->date('manufactured_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index('grn_id');
            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_lines');
    }
};
