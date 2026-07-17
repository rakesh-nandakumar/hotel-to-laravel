<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('opening_cash')->comment('LKR cents counted at open');
            $table->unsignedInteger('closing_cash')->nullable()->comment('counted at close');
            $table->integer('expected_cash')->nullable()->comment('opening + cash payments - cash refunds during shift');
            $table->integer('variance')->nullable()->comment('closing_cash - expected_cash');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
