<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods Received Note — the purchase document. `reference` is a free-text
     * supplier invoice/bill number; there is no supplier entity. Draft GRNs
     * can be edited freely; `receive()` posts batches + stock movements and
     * moves status to `received`, after which the GRN is immutable (correct
     * mistakes with a stock adjustment, not by un-posting).
     */
    public function up(): void
    {
        Schema::create('grns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('grn_no');
            $table->string('reference')->nullable();
            $table->foreignId('grn_status_id')->constrained('lookups');
            $table->date('received_at');
            $table->string('notes')->nullable();
            $table->unsignedInteger('total_cost')->default(0)->comment('cents, denormalised from lines');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'grn_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grns');
    }
};
