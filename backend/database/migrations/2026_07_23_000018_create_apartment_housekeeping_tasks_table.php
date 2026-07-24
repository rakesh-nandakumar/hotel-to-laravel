<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('apartment_units')->restrictOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_status_id')->constrained('lookups')->restrictOnDelete();
            $table->json('checklist')->comment('[{item, done}] from the unit type cleaning_checklist template');
            $table->text('notes')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('apartment_bookings')->nullOnDelete()
                ->comment('the checkout that triggered this task, if any');
            $table->foreignId('lease_id')->nullable()->constrained('apartment_leases')->nullOnDelete()
                ->comment('the termination that triggered this task, if any');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_housekeeping_tasks');
    }
};
