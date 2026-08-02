<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\Lookups\SettingType;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    private const CACHE_KEY_PREFIX = 'settings:all:';

    /**
     * @return array<string, mixed>
     */
    private static function all(): array
    {
        return Cache::rememberForever(self::cacheKey(), function (): array {
            return Setting::query()->get()->mapWithKeys(
                fn (Setting $setting) => [$setting->key => self::decode($setting->value)],
            )->all();
        });
    }

    /**
     * Keyed per tenant — every read here goes through the ambient tenant
     * scope (see App\Models\Concerns\BelongsToTenant), so the cache must be
     * too, or one tenant's settings would leak into another's cached view.
     */
    private static function cacheKey(?int $tenantId = null): string
    {
        return self::CACHE_KEY_PREFIX.($tenantId ?? app(CurrentContext::class)->tenantId() ?? 'none');
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
     * A deposit/advance amount that's configurable as either a percentage of
     * the total or a flat LKR-cents figure, per `{$modeKey}` ('percentage',
     * the default, or 'fixed'). A fixed amount is always capped to the total —
     * a misconfigured flat deposit can never exceed what's actually owed.
     */
    public static function depositAmount(int $total, string $modeKey, string $pctKey, string $fixedKey, float $defaultPct = 20): int
    {
        if (self::str($modeKey, 'percentage') === 'fixed') {
            return min((int) round(self::num($fixedKey, 0)), $total);
        }

        return (int) round($total * self::num($pctKey, $defaultPct) / 100);
    }

    /**
     * Type-validated write. Throws {@see ValidationException} on a type
     * mismatch (mirrors the Node route's inline validation) rather than
     * silently coercing bad input.
     *
     * $tenantId is required from the central admin panel — a CentralAdmin has
     * no ambient tenant context (TenantScope is unscoped for the `central`
     * guard), so it must say explicitly which tenant's setting it's editing.
     * Tenant-side call sites (none remain — settings management now lives
     * entirely in master control) would rely on the ambient scope instead.
     */
    public static function set(string $key, mixed $value, ?int $updatedBy = null, ?int $tenantId = null): Setting
    {
        $query = $tenantId !== null
            ? Setting::query()->withoutTenantScope()->where('tenant_id', $tenantId)
            : Setting::query();

        // A tenant only has rows for keys it has actually been seeded with, so
        // a key that's in the catalog but has never been written for THIS
        // tenant has nothing to update — which is every key of a tenant
        // provisioned before settings seeding existed, and every key added to
        // the catalog since a tenant was created. The central Settings screen
        // lists those keys (at their catalog default), so saving one must
        // materialise the row rather than 404.
        $setting = $query->where('key', $key)->first();

        // Validate BEFORE creating anything: the type comes from the existing
        // row or, failing that, the catalog. Creating first would leave a row
        // behind every time a value was rejected, silently converting "not
        // overridden" into "overridden with the default".
        $type = $setting?->type ?? self::catalogDefinition($key)['type'];

        self::assertValidForType($type, $value);

        $setting ??= self::createFromCatalog($key, $tenantId);

        $setting->update([
            'value' => json_encode($value),
            'updated_by' => $updatedBy,
        ]);

        // Invalidate the exact same cache key the read path (all()) would
        // have used — i.e. don't substitute the row's actual tenant_id when
        // $tenantId wasn't given; that would invalidate a different key than
        // the ambient one all()/get() actually read from and cached under.
        self::invalidate($tenantId);

        return $setting->refresh();
    }

    public static function invalidate(?int $tenantId = null): void
    {
        Cache::forget(self::cacheKey($tenantId));
    }

    /**
     * The catalog entry defining a setting key.
     *
     * An unknown key has no definition and is never created — that would let a
     * typo'd or hand-crafted request invent arbitrary rows. It raises the same
     * ModelNotFoundException the previous firstOrFail() did, which the app
     * renders as a 404.
     *
     * @return array{key: string, value: mixed, type: string, category: string, label: string, hint?: string}
     */
    private static function catalogDefinition(string $key): array
    {
        $definition = collect(SettingsSeeder::definitions())->firstWhere('key', $key);

        if ($definition === null) {
            throw (new ModelNotFoundException)->setModel(Setting::class, [$key]);
        }

        return $definition;
    }

    /**
     * Materialises a catalog-defined setting for a tenant that has no row for
     * it yet, carrying the catalog's type/category/label across so the value
     * still validates and renders exactly like a seeded one.
     */
    private static function createFromCatalog(string $key, ?int $tenantId): Setting
    {
        $definition = self::catalogDefinition($key);

        // tenant_id is only stamped from ambient context when left null (see
        // BelongsToTenant), so passing it explicitly is what lets a central
        // admin — who has no ambient tenant — write on a tenant's behalf.
        return Setting::query()->create([
            'tenant_id' => $tenantId ?? app(CurrentContext::class)->tenantId(),
            'key' => $key,
            'value' => json_encode($definition['value']),
            'type' => $definition['type'],
            'category' => $definition['category'],
            'label' => $definition['label'],
            'hint' => $definition['hint'] ?? null,
        ]);
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
            SettingType::COLOR => (! is_string($value) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $value))
                ? 'Value must be a hex color, e.g. #0462d3.'
                : null,
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['value' => $error]);
        }
    }
}
