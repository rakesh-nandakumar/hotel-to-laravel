<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bearer-less "this device may PIN-unlock as this user" grants, issued on
     * request to an already-authenticated session (POST /api/device-token) and
     * redeemed by POST /api/pin-login. Only the SHA-256 hash of the raw token
     * is stored — the same pattern Sanctum itself uses for personal access
     * tokens — so a stolen database dump can't be replayed as a live token.
     */
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
