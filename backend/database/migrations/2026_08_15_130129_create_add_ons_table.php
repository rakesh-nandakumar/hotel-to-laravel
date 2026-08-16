<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standalone sellable add-ons (extra cheese, extra curry, side of chips…).
     * Each add-on carries its own `send_to_kot` routing and its own inventory
     * tracking rules: a recipe-driven add-on uses `add_on_recipes`, a
     * unit-based add-on links straight to a batch-tracked ingredient via
     * `stock_ingredient_id` (1 add-on = 1 unit).
     *
     * Add-ons are their own order lines at the POS — independently priced,
     * routed, and deducted — linked to parent menu items and/or categories via
     * the `add_on_links` pivot.
     */
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price')->comment('LKR cents');
            $table->boolean('send_to_kot')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('stock_ingredient_id')->nullable()
                ->constrained('ingredients')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('add_ons');
    }
};
