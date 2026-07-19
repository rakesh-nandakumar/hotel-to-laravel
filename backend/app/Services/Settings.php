<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Lookups\SettingType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Typed, cached access to the business-configurable `settings` table
 * (VAT %, deposit %, cancellation policy, loyalty rates, ...). Mirrors the
 * Node app's lib/settings.ts: one cached key→value map, typed getters,
 * and type-aware validation on write.
 */
class Settings
{
    private const CACHE_KEY = 'settings:all';

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            return Setting::query()->get()->mapWithKeys(
                fn (Setting $setting) => [$setting->key => self::decode($setting->value)],
            )->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function num(string $key, float $default = 0): float
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public static function json(string $key, array $default = []): array
    {
        $value = self::get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * Type-validated write. Throws {@see ValidationException} on a type
     * mismatch (mirrors the Node route's inline validation) rather than
     * silently coercing bad input.
     */
    public static function set(string $key, mixed $value, ?int $updatedBy = null): Setting
    {
        $setting = Setting::query()->findOrFail($key);

        self::assertValidForType($setting->type, $value);

        $setting->update([
            'value' => json_encode($value),
            'updated_by' => $updatedBy,
        ]);

        self::invalidate();

        return $setting->refresh();
    }

    public static function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function decode(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        // Values written before a type existed, or edited directly in the DB,
        // may not be valid JSON — fall back to the raw string rather than losing data.
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private static function assertValidForType(string $type, mixed $value): void
    {
        $error = match ($type) {
            SettingType::NUMBER, SettingType::MONEY => ! is_numeric($value)
                ? 'Value must be a number.'
                : null,
            SettingType::PERCENT => ! is_numeric($value) || $value < 0 || $value > 100
                ? 'Value must be a number between 0 and 100.'
                : null,
            SettingType::BOOLEAN => ! is_bool($value)
                ? 'Value must be true or false.'
                : null,
            // Laravel's global ConvertEmptyStringsToNull middleware turns the ""
            // sent by "Remove logo" into null before it gets here, so null must
            // be accepted as "no image" — only a genuinely wrong type (number,
            // array, ...) should be rejected.
            SettingType::IMAGE => ($value !== null && ! is_string($value))
                ? 'Value must be an image.'
                : null,
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['value' => $error]);
        }
    }
}
