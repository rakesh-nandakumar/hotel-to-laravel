<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tills');
    }
};
