<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apartment_ledgers', function (Blueprint $table) {
            // A ledger belongs to exactly one of booking/lease/sale.
            $table->foreignId('sale_id')->nullable()->unique()->after('lease_id')
                ->constrained('apartment_sales')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apartment_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
