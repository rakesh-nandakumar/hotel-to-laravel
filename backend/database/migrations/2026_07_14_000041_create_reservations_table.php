<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignId('booking_channel_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('reservation_status_id')->constrained('lookups')->restrictOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0)->comment('under free-age per policy setting — not charged');
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->foreignId('group_booking_id')->nullable()->constrained('group_bookings')->nullOnDelete();
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('deposit_due')->default(0)->comment('LKR cents, from Setting % at booking');
            $table->json('pre_check_in')->nullable()->comment('guest-submitted pre-arrival form');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['check_in', 'check_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
