<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_no')->unique();
            $table->foreignId('property_id')->nullable()->constrained('apartment_properties')->nullOnDelete();
            $table->foreignId('unit_type_id')->constrained('apartment_unit_types')->restrictOnDelete();
            $table->string('floor')->nullable();
            $table->string('view')->nullable();
            $table->json('amenities')->nullable();
            $table->foreignId('listing_type_id')->constrained('lookups')->restrictOnDelete()->comment('rental | sale — gates which sub-module may book this unit');
            $table->foreignId('unit_status_id')->constrained('lookups')->restrictOnDelete();
            $table->unsignedInteger('sale_price')->nullable()->comment('LKR cents — listing price when listing_type = sale');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_status_id']);
            $table->index(['listing_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_units');
    }
};
