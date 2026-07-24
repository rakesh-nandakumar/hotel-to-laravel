<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of the chosen modifier at order time — name/price_delta copied
        // in, same reasoning as OrderItem already snapshotting name/unit_price off
        // MenuItem: a later edit/removal of the modifier must never change history.
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('menu_item_modifier_id')->nullable()->constrained('menu_item_modifiers')->nullOnDelete();
            $table->string('name');
            $table->integer('price_delta')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
    }
};
