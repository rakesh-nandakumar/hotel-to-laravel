<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('table_no')->unique();
            $table->foreignId('dining_area_id')->nullable()->constrained('dining_areas')->nullOnDelete();
            $table->integer('capacity')->default(2);
            $table->foreignId('table_status_id')->constrained('lookups')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['table_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
