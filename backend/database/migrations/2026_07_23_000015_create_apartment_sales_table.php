<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_sales', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('unit_id')->constrained('apartment_units')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('apartment_customers')->restrictOnDelete()->comment('Buyer');
            $table->foreignId('sale_status_id')->constrained('lookups')->restrictOnDelete();
            $table->unsignedInteger('agreed_price')->comment('LKR cents — the full ledger total from creation');
            $table->date('reserved_until')->nullable()->comment('Option-hold expiry — auto-released if no agreement signed by then');
            $table->timestamp('agreement_signed_at')->nullable();
            $table->timestamp('handover_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sale_status_id']);
            $table->index(['unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_sales');
    }
};
