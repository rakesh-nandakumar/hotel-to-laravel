<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('id_number')->nullable()->comment('NIC/passport');
            $table->string('nationality')->nullable();
            $table->text('preferences')->nullable();
            $table->integer('loyalty_points')->default(0)->comment('Denormalized running total — kept in sync with loyalty_transactions');
            $table->unsignedBigInteger('lifetime_spend')->default(0)->comment('LKR cents, denormalized running total');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['name']);
            $table->index(['phone']);
            $table->index(['email']);
            $table->index(['id_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
