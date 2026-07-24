<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Self-referencing: a child order created by OrderService::splitBill()
            // points back at the original; OrderService::mergeOrders() reuses the
            // same column on the orders folded into their target.
            $table->foreignId('parent_order_id')->nullable()->after('id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_order_id');
        });
    }
};
