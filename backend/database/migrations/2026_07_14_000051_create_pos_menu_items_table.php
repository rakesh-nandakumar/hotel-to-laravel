<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No soft-deletes here on purpose: `active` is the domain-meaningful
     * archive flag (matching the Node app exactly) — an item that appears in
     * past orders is archived (active=false), never soft-deleted, so it stays
     * fully visible in order history and reports.
     */
    public function up(): void
    {
        Schema::create('pos_menu_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('item_no')->nullable()->unique()->comment('printed menu number — quick POS entry ("#12")');
            $table->string('name');
            $table->foreignId('menu_category_id')->constrained('pos_menu_categories')->restrictOnDelete();
            $table->unsignedInteger('price')->comment('LKR cents');
            $table->string('description')->default('');
            $table->boolean('sold_out')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_menu_items');
    }
};
