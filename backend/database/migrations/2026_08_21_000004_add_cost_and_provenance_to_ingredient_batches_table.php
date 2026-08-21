<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->integer('unit_cost')->nullable()->comment('cents per unit, from the GRN line');
            $table->date('manufactured_at')->nullable();
            $table->string('batch_no')->nullable();
            $table->foreignId('grn_line_id')->nullable()->after('note')
                ->constrained('grn_lines')->nullOnDelete();

            $table->index(['ingredient_id', 'expiry_date', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->dropIndex(['ingredient_id', 'expiry_date', 'received_at']);
            $table->dropConstrainedForeignId('grn_line_id');
            $table->dropColumn(['unit_cost', 'manufactured_at', 'batch_no']);
        });
    }
};
