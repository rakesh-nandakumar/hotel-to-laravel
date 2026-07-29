<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One QR ordering point per room or per dining table — mirrors the
     * nullable room_id / dining_table_id sibling-columns pattern `orders`
     * already uses for "which of the two this belongs to", rather than a
     * polymorphic relation (nothing else in this app uses morphTo for this
     * kind of either/or).
     */
    public function up(): void
    {
        Schema::create('qr_ordering_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->unique()->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('dining_table_id')->nullable()->unique()->constrained('dining_tables')->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_ordering_points');
    }
};
