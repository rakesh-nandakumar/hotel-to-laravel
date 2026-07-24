<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apartment_leases', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('unit_id')->constrained('apartment_units')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('apartment_customers')->restrictOnDelete();
            $table->foreignId('lease_status_id')->constrained('lookups')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable()->comment('null = open-ended / month-to-month, runs until terminated');
            $table->unsignedInteger('monthly_rent')->comment('LKR cents/month');
            $table->unsignedTinyInteger('rent_due_day')->default(1)->comment('Day of month rent is charged, 1-28');
            $table->unsignedInteger('security_deposit')->default(0)->comment('LKR cents');
            $table->unsignedInteger('notice_period_days')->default(30);
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->string('termination_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lease_status_id']);
            $table->index(['unit_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apartment_leases');
    }
};
