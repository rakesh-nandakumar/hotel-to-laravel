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
        Schema::create('venue_extra_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('amount')->comment('Charge amount in cents');
            $table->string('charge_type')->default('misc')->comment('Type: service_charge, ac, table, cleaning, etc.');
            $table->boolean('is_percentage')->default(false)->comment('If true, amount is a percentage of base total');
            $table->unsignedInteger('sort_order')->default(0)->comment('For display ordering');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['venue_booking_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venue_extra_charges');
    }
};
