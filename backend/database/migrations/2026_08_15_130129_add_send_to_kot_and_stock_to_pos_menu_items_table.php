<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Routing + stock configuration for POS products:
     *
     * - `send_to_kot` — whether the item routes to the kitchen display/printer.
     *   false = "direct fulfill" (picked from front-of-house stock, e.g. bottled
     *   drinks, packaged snacks) and bypasses the KOT stream entirely.
     *
     * - `stock_ingredient_id` — optional direct link to a unit-stocked ingredient
     *   (1 portion = 1 unit of the ingredient). Products with a recipe use that
     *   recipe instead; unit-based products without a recipe link straight to a
     *   batch-tracked ingredient so every product deducts FEFO from expiry batches.
     */
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->boolean('send_to_kot')->default(true)->after('sold_out');
            $table->foreignId('stock_ingredient_id')->nullable()->after('menu_category_id')
                ->constrained('ingredients')->nullOnDelete()->index();
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_ingredient_id');
            $table->dropColumn('send_to_kot');
        });
    }
};
