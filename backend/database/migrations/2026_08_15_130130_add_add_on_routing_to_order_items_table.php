<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order lines can now be either a menu item OR a standalone add-on:
     *
     * - `menu_item_id` becomes nullable — add-on lines have no menu item.
     * - `add_on_id` points at the add-on when the line is an add-on.
     * - `send_to_kot` snapshots the routing decision at order time (an item's
     *   `send_to_kot` can change later; the historical ticket stays truthful).
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->nullable()->change();
            $table->foreignId('add_on_id')->nullable()->after('menu_item_id')
                ->constrained('add_ons')->nullOnDelete();
            $table->boolean('send_to_kot')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('send_to_kot');
            $table->dropConstrainedForeignId('add_on_id');
            $table->foreignId('menu_item_id')->nullable(false)->change();
        });
    }
};
