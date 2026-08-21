<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order lines can now be a menu item, an add-on, OR a product — a
     * directly sellable, non-recipe stock item (bottled drink, packaged
     * snack). `product_id` points at `ingredients` because that is where
     * products live (see the `inventory_kind` migration); the column name
     * reads honestly at the call site (`$item->product`).
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('add_on_id')
                ->constrained('ingredients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
