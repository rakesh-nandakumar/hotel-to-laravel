<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->nullable()->unique()->comment('offline-POS replay safety');
            $table->foreignId('payment_kind_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('lookups')->restrictOnDelete();
            $table->unsignedInteger('amount')->comment('LKR cents; refunds stored positive with kind=refund');
            $table->string('reference')->nullable()->comment('card slip no, bank ref, QR txn id');
            $table->text('reason')->nullable()->comment('mandatory for refunds — enforced in BillingService');
            $table->foreignId('folio_id')->nullable()->constrained('folios')->nullOnDelete();
            $table->foreignId('corporate_account_id')->nullable()->constrained('corporate_accounts')->nullOnDelete()
                ->comment('month-end settlement payments');
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
