<?php

use App\Models\DeviceToken;
use App\Services\DeviceTokenService;
use App\Services\Settings;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
});

it('issues a device token for the current session', function () {
    $manager = staffWithRole('Manager');

    $response = $this->actingAs($manager)->postJson('/api/device-token')->assertOk();

    expect($response->json('device_token'))->toBeString()->and(strlen($response->json('device_token')))->toBeGreaterThan(20);
    expect(DeviceToken::where('user_id', $manager->id)->count())->toBe(1);
});

it('logs in via PIN using a previously issued device token', function () {
    $manager = staffWithRole('Manager');
    $manager->update(['pin_hash' => Hash::make('4242')]);
    $rawToken = app(DeviceTokenService::class)->issue($manager);

    $this->postJson('/api/pin-login', ['device_token' => $rawToken, 'pin' => '4242'])->assertOk();

    $this->assertAuthenticatedAs($manager);
});

it('rejects PIN login with the wrong PIN or an unknown device token', function () {
    $manager = staffWithRole('Manager');
    $manager->update(['pin_hash' => Hash::make('4242')]);
    $rawToken = app(DeviceTokenService::class)->issue($manager);

    $this->postJson('/api/pin-login', ['device_token' => $rawToken, 'pin' => '0000'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->assertGuest();

    $this->postJson('/api/pin-login', ['device_token' => str_repeat('x', 64), 'pin' => '4242'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->assertGuest();
});

it('blocks PIN login for an account that requires two-factor sign-in', function () {
    $manager = staffWithRole('Manager');
    $manager->update(['pin_hash' => Hash::make('4242'), 'two_factor_required' => true]);
    $rawToken = app(DeviceTokenService::class)->issue($manager);

    $this->postJson('/api/pin-login', ['device_token' => $rawToken, 'pin' => '4242'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->assertGuest();
});

it('lets a manager set and clear a staff member PIN', function () {
    $manager = staffWithRole('Manager');
    $chef = staffWithRole('Chef');

    $this->actingAs($manager)->putJson("/api/staff/{$chef->id}/pin", ['pin' => '135790'])->assertOk();
    expect(Hash::check('135790', $chef->fresh()->pin_hash))->toBeTrue();

    $this->actingAs($manager)->putJson("/api/staff/{$chef->id}/pin", ['pin' => null])->assertOk();
    expect($chef->fresh()->pin_hash)->toBeNull();
});

it('rejects a PIN outside the 4-6 digit range and blocks non-manager roles', function () {
    $manager = staffWithRole('Manager');
    $housekeeper = staffWithRole('Housekeeper');
    $chef = staffWithRole('Chef');

    $this->actingAs($manager)->putJson("/api/staff/{$chef->id}/pin", ['pin' => '12'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');

    $this->actingAs($housekeeper)->putJson("/api/staff/{$chef->id}/pin", ['pin' => '1234'])->assertForbidden();
});

it('lets any staff read business settings but hides integrations from non-full-admins', function () {
    $housekeeper = staffWithRole('Housekeeper');
    $admin = fullAdmin();

    $staffView = $this->actingAs($housekeeper)->getJson('/api/hotel-settings')->assertOk();
    expect(collect($staffView->json('settings'))->pluck('category'))->not->toContain('integrations');

    $adminView = $this->actingAs($admin)->getJson('/api/hotel-settings')->assertOk();
    expect(collect($adminView->json('settings'))->pluck('category'))->toContain('integrations');
});

it('lets a manager update a business setting but not an integrations setting', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->putJson('/api/hotel-settings/billing.vat_pct', ['value' => 12])->assertOk();
    expect(Settings::num('billing.vat_pct'))->toBe(12.0);

    $this->actingAs($manager)->putJson('/api/hotel-settings/integrations.whatsapp_enabled', ['value' => true])
        ->assertForbidden();
});

it('lets a full administrator update integrations settings and validates the value type', function () {
    $admin = fullAdmin();

    $this->actingAs($admin)->putJson('/api/hotel-settings/integrations.whatsapp_enabled', ['value' => true])->assertOk();
    expect(Settings::bool('integrations.whatsapp_enabled'))->toBeTrue();

    $this->actingAs($admin)->putJson('/api/hotel-settings/billing.vat_pct', ['value' => 150])
        ->assertUnprocessable()->assertJsonValidationErrors('value');
});

it('blocks housekeeper from updating any setting', function () {
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($housekeeper)->putJson('/api/hotel-settings/billing.vat_pct', ['value' => 5])->assertForbidden();
});

it('lets a manager upload and remove the hotel logo', function () {
    $manager = staffWithRole('Manager');
    $dataUri = 'data:image/png;base64,'.str_repeat('A', 80_000);

    $this->actingAs($manager)->putJson('/api/hotel-settings/hotel.logo_url', ['value' => $dataUri])->assertOk();
    expect(Settings::str('hotel.logo_url'))->toBe($dataUri);

    // The frontend "Remove logo" button sends "" — Laravel's global
    // ConvertEmptyStringsToNull middleware turns that into null before it
    // reaches the controller, so null must be accepted as "no image".
    $this->actingAs($manager)->putJson('/api/hotel-settings/hotel.logo_url', ['value' => ''])->assertOk();
    expect(Settings::str('hotel.logo_url'))->toBe('');
});

it('lets a manager update the theme colors and rejects a non-hex value', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->putJson('/api/hotel-settings/theme.primary', ['value' => '#ff8800'])->assertOk();
    expect(Settings::str('theme.primary'))->toBe('#ff8800');

    $this->actingAs($manager)->putJson('/api/hotel-settings/theme.primary', ['value' => 'not-a-color'])
        ->assertUnprocessable()->assertJsonValidationErrors('value');
});
