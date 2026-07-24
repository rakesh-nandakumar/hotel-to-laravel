<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('unit_id')->constrained('apartment_units')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('apartment_customers')->restrictOnDelete();
            $table->foreignId('booking_status_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('channel_id')->constrained('lookups')->restrictOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedInteger('adults')->default(1);
            $table->unsignedInteger('children')->default(0);
            $table->unsignedInteger('nightly_rate')->comment('LKR cents/night actually charged — snapshot, independent of later unit-type rate changes');
            $table->string('rate_basis', 10)->default('nightly')->comment('nightly | weekly | monthly — which tier nightly_rate was resolved from');
            $table->unsignedInteger('deposit_due')->default(0)->comment('LKR cents — security/booking deposit');
            $table->text('notes')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['booking_status_id']);
            $table->index(['unit_id', 'check_in', 'check_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_bookings');
    }
};
