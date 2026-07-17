<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_logs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vehicle_no')->nullable();
            $table->string('purpose')->nullable();
            $table->timestamp('time_in')->useCurrent();
            $table->timestamp('time_out')->nullable();
            $table->foreignId('logged_by_id')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_logs');
    }
};
