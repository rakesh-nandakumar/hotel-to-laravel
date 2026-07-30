<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 63)->unique();
            $table->string('status', 20)->default('trial')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('central_admins')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('central_admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
