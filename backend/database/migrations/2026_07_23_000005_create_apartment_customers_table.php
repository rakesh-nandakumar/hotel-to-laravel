<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('id_number')->nullable()->comment('NIC/passport');
            $table->string('nationality')->nullable();
            $table->boolean('is_company')->default(false)->comment('Corporate tenant/buyer — company_name/company_reg_no apply');
            $table->string('company_name')->nullable();
            $table->string('company_reg_no')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
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
        Schema::dropIfExists('apartment_customers');
    }
};
