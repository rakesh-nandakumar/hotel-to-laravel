<?php

use App\Models\CentralAdmin;
use Illuminate\Support\Facades\Hash;

/**
 * The only supported way to get a master control login on a real deployment —
 * CentralAdminSeeder skips production, so without this command the panel that
 * provisions every tenant can't be signed into at all.
 */
it('creates a platform operator who can then sign in', function () {
    $this->artisan('central:create-admin', [
        '--name' => 'Ops Lead',
        '--email' => 'ops@vellix.test',
        '--password' => 'super-secret-1',
    ])->assertSuccessful();

    $admin = CentralAdmin::query()->where('email', 'ops@vellix.test')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('Ops Lead')
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->password)->not->toBe('super-secret-1')
        ->and(Hash::check('super-secret-1', $admin->password))->toBeTrue();

    $this->postJson('/api/central/login', [
        'email' => 'ops@vellix.test',
        'password' => 'super-secret-1',
    ])->assertOk();
});

it('refuses a duplicate email rather than hijacking the existing operator', function () {
    CentralAdmin::factory()->create(['email' => 'taken@vellix.test']);

    $this->artisan('central:create-admin', [
        '--name' => 'Impostor',
        '--email' => 'taken@vellix.test',
        '--password' => 'super-secret-1',
    ])->assertFailed();

    expect(CentralAdmin::query()->where('email', 'taken@vellix.test')->count())->toBe(1);
});

it('rejects a password below the minimum length', function () {
    $this->artisan('central:create-admin', [
        '--name' => 'Ops Lead',
        '--email' => 'short@vellix.test',
        '--password' => 'short',
    ])->assertFailed();

    expect(CentralAdmin::query()->where('email', 'short@vellix.test')->exists())->toBeFalse();
});
