<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('icon', 60)->nullable();
            $table->string('route_name', 120)->nullable();
            $table->string('module_key', 80)->nullable();
            $table->json('actions');
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'order']);
            $table->index('module_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
