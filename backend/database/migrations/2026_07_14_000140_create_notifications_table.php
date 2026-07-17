<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** No updated_at — status transitions are tracked via sent_at/error, matching Node's Notification model exactly. */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type')->comment('BOOKING_CONFIRMATION | PRE_ARRIVAL | FEEDBACK_REQUEST | VENUE_CONFIRMATION | VENUE_PAYMENT_REMINDER | VENUE_PRE_EVENT | LOW_STOCK | FOOD_EXPIRY | INTEGRATION_TEST');
            $table->foreignId('notification_channel_id')->constrained('lookups')->restrictOnDelete();
            $table->string('to');
            $table->string('subject');
            $table->text('body');
            $table->foreignId('notification_status_id')->constrained('lookups')->restrictOnDelete();
            $table->string('ref_type')->nullable()->comment('loose reference, not a real FK — matches source system');
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();

            $table->index(['type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
