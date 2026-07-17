<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->unsignedInteger('nightly_rate')->comment('LKR cents — locked at booking, after any corporate discount');
            $table->foreignId('bill_to_guest_id')->nullable()->constrained('guests')->nullOnDelete()
                ->comment('Group bookings: bill this room to a specific guest instead of the group invoice');
            $table->timestamps();

            $table->unique(['reservation_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_rooms');
    }
};
