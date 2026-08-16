<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot linking an add-on to its parent menu items and/or categories.
     * `menu_item_id` and `menu_category_id` are both nullable — an add-on can
     * be scoped to a single item, a whole category, or both.
     */
    public function up(): void
    {
        Schema::create('add_on_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('add_on_id')->constrained('add_ons')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('pos_menu_items')->cascadeOnDelete();
            $table->foreignId('menu_category_id')->nullable()->constrained('pos_menu_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['add_on_id', 'menu_item_id', 'menu_category_id']);
            $table->index(['menu_item_id']);
            $table->index(['menu_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('add_on_links');
    }
};
