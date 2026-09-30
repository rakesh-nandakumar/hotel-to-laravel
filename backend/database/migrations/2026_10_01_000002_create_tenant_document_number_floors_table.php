<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lowest number a document series may never reissue. Written by a data
     * purge run with "continue numbering": without it, document codes are
     * derived from the highest surviving row (see DocumentNumberService), so
     * deleting the newest invoices would hand their numbers out again.
     */
    public function up(): void
    {
        Schema::create('tenant_document_number_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('prefix', 64)->comment('e.g. INV-2026- / RSV-');
            $table->unsignedBigInteger('last_number');
            $table->timestamps();

            $table->unique(['tenant_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_document_number_floors');
    }
};
