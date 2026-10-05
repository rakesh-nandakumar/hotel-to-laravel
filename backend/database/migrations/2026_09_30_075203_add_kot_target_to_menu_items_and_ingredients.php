<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_menu_items', 'kot_target')) {
                $table->string('kot_target')->default('kitchen')->after('menu_category_id');
            }
        });

        Schema::table('ingredients', function (Blueprint $table) {
            if (! Schema::hasColumn('ingredients', 'kot_target')) {
                $table->string('kot_target')->default('kitchen')->after('inventory_kind_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            if (Schema::hasColumn('pos_menu_items', 'kot_target')) {
                $table->dropColumn('kot_target');
            }
        });

        Schema::table('ingredients', function (Blueprint $table) {
            if (Schema::hasColumn('ingredients', 'kot_target')) {
                $table->dropColumn('kot_target');
            }
        });
    }
};
