<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('maintenance_issues', function (Blueprint $table) {
            $table->foreignId('venue_id')->nullable()->after('room_id')->constrained('venues')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_id');
        });
    }
};
