<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('base_salary')->default(0)->after('status')->comment('monthly basic, LKR cents');
            $table->unsignedInteger('ot_hourly_rate')->default(0)->comment('overtime per hour, LKR cents');
            $table->unsignedInteger('monthly_allowance')->default(0);
            $table->boolean('epf_enabled')->default(true);
            $table->string('epf_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'ot_hourly_rate', 'monthly_allowance', 'epf_enabled', 'epf_number']);
        });
    }
};
