<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_unit_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('max_occupancy')->default(2);
            $table->unsignedInteger('bedrooms')->default(1);
            $table->unsignedInteger('bathrooms')->default(1);
            $table->unsignedInteger('size_sqft')->nullable();
            $table->json('amenities')->nullable();
            $table->unsignedInteger('nightly_rate')->nullable()->comment('LKR cents/night — null when the type is sale-only');
            $table->unsignedInteger('weekly_rate')->nullable()->comment('LKR cents/week — falls back to nightly_rate x 7 when null');
            $table->unsignedInteger('monthly_rate')->nullable()->comment('LKR cents/month — also the base long-term lease rent');
            $table->unsignedInteger('min_nights')->default(1)->comment('Minimum stay length for a short-term booking of this type');
            $table->unsignedInteger('cleaning_fee')->default(0)->comment('LKR cents, flat per short-term booking');
            $table->unsignedInteger('extra_guest_fee')->default(0)->comment('LKR cents/night per guest over max_occupancy');
            $table->json('item_checklist')->nullable()->comment('Move-in/move-out item verification template');
            $table->json('cleaning_checklist')->nullable()->comment('Turnover housekeeping task template');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_unit_types');
    }
};
