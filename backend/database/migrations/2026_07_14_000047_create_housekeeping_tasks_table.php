<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimal shape needed for reservation checkout to auto-create a cleaning
     * task per room. The Housekeeping module (task listing/assignment/checklist
     * completion endpoints) is built on top of this table later.
     */
    public function up(): void
    {
        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_status_id')->constrained('lookups')->restrictOnDelete();
            $table->json('checklist')->comment('[{item, done}] from the room type template');
            $table->text('notes')->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete()
                ->comment('the checkout that triggered this task, if any');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_tasks');
    }
};
