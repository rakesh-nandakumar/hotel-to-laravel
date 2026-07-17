<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('pos_menu_items')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('qty', 12, 3)->comment('per 1 portion');
            $table->timestamps();

            $table->unique(['menu_item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
