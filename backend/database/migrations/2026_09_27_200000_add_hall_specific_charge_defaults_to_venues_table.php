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
        Schema::table('venues', function (Blueprint $table) {
            // Hall-specific charge defaults stored as JSON (amounts in cents)
            $table->json('default_charge_defaults')->nullable()->after('use_package_pricing')->comment('Default charges for AC, Table, Cleaning Staff, etc. in cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn('default_charge_defaults');
        });
    }
};
