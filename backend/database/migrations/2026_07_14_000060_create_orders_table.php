<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No separate human-readable order-number sequence like Node's `orderNo`
     * (autoincrement alongside a cuid PK) — Laravel's own `id` is already a
     * sequential integer, so it doubles as the display order number.
     * No SoftDeletes: `order_status_id = VOID` is the soft-void mechanism,
     * matching Folio's own pattern.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('client_key')->nullable()->unique()->comment('offline-POS idempotency key');
            $table->foreignId('order_type_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('dining_mode_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('order_status_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('kot_status_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('customer_name')->nullable()->comment('walk-in label');
            $table->text('notes')->nullable();
            $table->integer('subtotal')->default(0);
            $table->integer('discount')->default(0)->comment('LKR cents, positive number, subtracted');
            $table->string('discount_reason')->nullable();
            $table->foreignId('discount_by_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('manager who authorized — a real FK, unlike Node\'s bare-string discountById');
            $table->integer('service_charge')->default(0);
            $table->integer('vat')->default(0);
            $table->integer('total')->default(0);
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['order_status_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
