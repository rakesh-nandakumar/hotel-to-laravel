<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('profile_image')->nullable()->after('phone');
            $table->string('status', 20)->default('active')->after('profile_image');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->unsignedInteger('failed_login_count')->default(0)->after('last_login_ip');
            $table->timestamp('locked_until')->nullable()->after('failed_login_count');
            $table->string('password_reset_otp_hash')->nullable()->after('locked_until');
            $table->timestamp('password_reset_otp_expires_at')->nullable()->after('password_reset_otp_hash');
            $table->unsignedBigInteger('role_id')->nullable()->after('password_reset_otp_expires_at');
            $table->unsignedBigInteger('created_by')->nullable()->after('role_id');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->index('status');
            $table->index('role_id');
            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['role_id']);
            $table->dropIndex(['locked_until']);

            $table->dropColumn([
                'phone',
                'profile_image',
                'status',
                'last_login_at',
                'last_login_ip',
                'failed_login_count',
                'locked_until',
                'password_reset_otp_hash',
                'password_reset_otp_expires_at',
                'role_id',
                'created_by',
                'updated_by',
            ]);
        });
    }
};
