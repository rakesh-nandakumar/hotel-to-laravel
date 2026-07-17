<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('max_occupancy')->default(2);
            $table->string('bed_config')->nullable();
            $table->json('amenities')->nullable();
            $table->unsignedInteger('weekday_rate')->comment('LKR cents/night');
            $table->unsignedInteger('weekend_rate')->comment('LKR cents/night');
            $table->json('item_checklist')->nullable()->comment('Check-in/out item verification template');
            $table->json('cleaning_checklist')->nullable()->comment('Housekeeping task template');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
