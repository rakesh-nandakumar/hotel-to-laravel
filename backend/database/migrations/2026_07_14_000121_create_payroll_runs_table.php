<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** No soft-deletes: a DRAFT run is genuinely hard-deletable (Node does this); FINALIZED runs can never be deleted at all. */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('month')->unique()->comment('"2026-07"');
            $table->foreignId('payroll_status_id')->constrained('lookups')->restrictOnDelete();
            $table->foreignId('run_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
