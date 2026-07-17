<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `venue_id` deferred until Module 10 (Venues) — Node's MaintenanceIssue
     * can reference either a Room or a Venue (no DB-level XOR either way, see
     * phase2-nodejs-schema memory edge case #4). Only Room support exists for
     * now; `room_id` is required at the application level until Venues lands.
     */
    public function up(): void
    {
        Schema::create('maintenance_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->text('description');
            $table->foreignId('maintenance_status_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('logged_by_id')->constrained('users')->restrictOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_issues');
    }
};
