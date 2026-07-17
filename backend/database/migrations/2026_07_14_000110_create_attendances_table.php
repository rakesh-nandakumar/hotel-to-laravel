<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No created_at/updated_at — clock_in already is the record's timestamp,
     * matching Node's Attendance model exactly (no separate audit columns).
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('clock_in');
            $table->timestamp('clock_out')->nullable();
            $table->string('note')->nullable();

            $table->index(['user_id', 'clock_in']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
