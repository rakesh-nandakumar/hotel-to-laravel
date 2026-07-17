<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();
            $table->integer('points')->comment('Positive = earn, negative = redeem');
            $table->string('reason');
            $table->string('ref_type')->nullable()->comment('Loose reference, e.g. folio/order/venue — not a real FK, matches the source system');
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guest_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
