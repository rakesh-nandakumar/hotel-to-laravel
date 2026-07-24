<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_maintenance_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('apartment_units')->restrictOnDelete();
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
        Schema::dropIfExists('apartment_maintenance_issues');
    }
};
