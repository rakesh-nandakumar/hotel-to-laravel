<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('max_capacity');
            $table->json('facilities')->nullable();
            $table->unsignedInteger('hourly_rate')->comment('LKR cents — editable, not fixed');
            $table->unsignedInteger('half_day_rate');
            $table->unsignedInteger('full_day_rate');
            $table->boolean('active')->default(true);
            $table->foreignId('branch_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
