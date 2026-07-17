<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('floor')->nullable();
            $table->string('view')->nullable();
            $table->json('amenities')->nullable();
            $table->foreignId('room_status_id')->constrained('lookups')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['room_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
