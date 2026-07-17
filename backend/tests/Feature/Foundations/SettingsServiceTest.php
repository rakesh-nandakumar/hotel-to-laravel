<?php

use App\Models\Setting;
use App\Services\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
});

it('is idempotent — re-running the seeder never overwrites an edited value', function () {
    Setting::query()->where('key', 'billing.vat_pct')->update(['value' => json_encode(18)]);

    $this->seed(SettingsSeeder::class);

    expect(Settings::num('billing.vat_pct'))->toBe(18.0);
});

it('reads typed values correctly', function () {
    expect(Settings::num('payroll.epf_employee_pct'))->toBe(8.0)
        ->and(Settings::str('hotel.name'))->toBe('Mount View Hotel')
        ->and(Settings::bool('integrations.whatsapp_enabled'))->toBeFalse()
        ->and(Settings::json('pricing.weekend_days'))->toBe([0, 6])
        ->and(Settings::json('policies.cancellation_rules'))->toHaveCount(3);
});

it('falls back to the given default for a missing key', function () {
    expect(Settings::num('does.not.exist', 42))->toBe(42.0);
});

it('caches the settings map and invalidates it on write', function () {
    expect(Settings::num('currency.usd_rate'))->toBe(300.0);

    Settings::set('currency.usd_rate', 310);

    expect(Settings::num('currency.usd_rate'))->toBe(310.0);
});

it('rejects an out-of-range percent value', function () {
    Settings::set('billing.vat_pct', 150);
})->throws(ValidationException::class);

it('rejects a non-boolean value for a boolean setting', function () {
    Settings::set('integrations.whatsapp_enabled', 'yes');
})->throws(ValidationException::class);

it('rejects a non-numeric value for a number setting', function () {
    Settings::set('currency.usd_rate', 'a lot');
})->throws(ValidationException::class);
