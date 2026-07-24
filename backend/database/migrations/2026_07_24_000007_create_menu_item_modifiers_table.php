<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('menu_item_modifier_groups')->cascadeOnDelete();
            $table->string('name');
            // Cents, same integer-money convention as everything else — added
            // on top of the menu item's base price, can be 0 (e.g. "Regular").
            $table->integer('price_delta')->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_modifiers');
    }
};
