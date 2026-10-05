<?php

namespace App\Services\Tenancy\DataPurge;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The encrypted receipt a preview hands the operator and a destructive request
 * must present. It pins the tenant, the operator and exactly what was
 * previewed, and it makes the server enforce the confirmation delay: a token
 * younger than `min_confirm_seconds` (a script replaying preview → purge in
 * one breath) or older than `token_ttl_seconds` (a stale preview) is refused.
 */
final class PurgeToken
{
    public const PURGE = 'purge';

    public const RESTORE = 'restore';

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function issue(string $kind, array $claims): string
    {
        return Crypt::encryptString(json_encode([...$claims, 'kind' => $kind, 'issued_at' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function read(string $token, string $kind): array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw self::invalid('This confirmation is not valid. Preview again.');
        }

        if (($claims['kind'] ?? null) !== $kind) {
            throw self::invalid('This confirmation is not valid. Preview again.');
        }

        $age = now()->getTimestamp() - (int) ($claims['issued_at'] ?? 0);

        if ($age > (int) config('tenancy-purge.token_ttl_seconds')) {
            throw self::invalid('This preview has expired. Preview again to continue.');
        }

        if ($age < (int) config('tenancy-purge.min_confirm_seconds')) {
            throw self::invalid('Confirmed too quickly. Review the preview, wait for the countdown, then confirm.');
        }

        return $claims;
    }

    private static function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['token' => $message]);
    }
}
