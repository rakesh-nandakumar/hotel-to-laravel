<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('client_name');
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->string('event_type')->nullable();
            $table->date('date');
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->foreignId('duration_type_id')->constrained('lookups')->restrictOnDelete();
            $table->decimal('hours', 5, 2)->nullable();
            $table->unsignedInteger('guest_count')->default(0);
            $table->text('seating')->nullable();
            $table->text('av_needs')->nullable();
            $table->text('decoration')->nullable();
            $table->boolean('catering_by_hotel')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('venue_booking_status_id')->constrained('lookups')->restrictOnDelete();
            $table->unsignedInteger('deposit_due')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['venue_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_bookings');
    }
};
