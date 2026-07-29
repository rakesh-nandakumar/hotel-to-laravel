<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Cents per unit. Nullable — items without a cost simply show "—"
            // on the Food Cost % report rather than a bogus 0% margin.
            $table->integer('unit_cost')->nullable()->after('low_stock_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
