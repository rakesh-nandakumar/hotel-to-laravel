<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('pos_menu_items')->restrictOnDelete();
            $table->string('name')->comment('snapshot at order time');
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_price')->comment('snapshot, LKR cents');
            $table->unsignedInteger('amount');
            $table->string('notes')->nullable();
            $table->boolean('voided')->default(false);
            $table->string('void_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
