<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanently stores one computed daily-report snapshot per business
     * date — a night audit can only be run once per date (enforced by the
     * unique index, not just application logic).
     */
    public function up(): void
    {
        Schema::create('night_audits', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->json('data');
            $table->foreignId('run_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('run_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_audits');
    }
};
