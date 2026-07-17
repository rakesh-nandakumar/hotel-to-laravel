<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Email-OTP second factor. Mirrors the password_reset_otp_*
            // pattern: only a hash is ever stored, with expiry, a bounded
            // attempt counter, and a resend timestamp for cooldowns.
            $table->boolean('two_factor_email_enabled')->default(false)->after('two_factor_required');
            $table->string('otp_hash')->nullable()->after('two_factor_email_enabled');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_hash');
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
            $table->timestamp('last_otp_sent_at')->nullable()->after('otp_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_email_enabled',
                'otp_hash',
                'otp_expires_at',
                'otp_attempts',
                'last_otp_sent_at',
            ]);
        });
    }
};
