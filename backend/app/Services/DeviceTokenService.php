<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Bearer-less "this device may PIN-unlock as this user" grants. Ported from
 * the Node app's signDeviceToken()/verifyDeviceToken() (there a self-verifying
 * signed JWT with no server-side state); here as an opaque random token whose
 * SHA-256 hash is stored — the same pattern Sanctum uses for personal access
 * tokens — since this app already leans on a database, not stateless JWTs.
 */
class DeviceTokenService
{
    private const TTL_DAYS = 60;

    /** Mint a new device token for an already-authenticated user. Returned once — only the hash is kept. */
    public function issue(User $user): string
    {
        $raw = Str::random(64);

        DeviceToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return $raw;
    }

    public function resolve(string $rawToken): ?User
    {
        $deviceToken = DeviceToken::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->where('expires_at', '>', now())
            ->with('user')
            ->first();

        return $deviceToken?->user;
    }
}
