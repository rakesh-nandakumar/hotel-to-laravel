<?php

use App\Models\User;
use Database\Seeders\AdminUsersSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
});

it('seeds one login-able account per operational role', function () {
    $this->seed(AdminUsersSeeder::class);

    $expected = [
        'admin@vellix.com' => 'Full Administrator',
        'manager@vellix.lk' => 'Manager',
        'owner@vellix.lk' => 'Owner',
        'housekeeper@vellix.lk' => 'Housekeeper',
        'chef@vellix.lk' => 'Chef',
        'security@vellix.lk' => 'Security',
    ];

    foreach ($expected as $email => $roleName) {
        $user = User::query()->where('email', $email)->firstOrFail();
        expect($user->status)->toBe(User::STATUS_ACTIVE)
            ->and($user->roles->pluck('name'))->toContain($roleName);

        $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
    }
});

it('is idempotent and skipped entirely in production', function () {
    $this->seed(AdminUsersSeeder::class);
    $countBefore = User::query()->count();

    $this->seed(AdminUsersSeeder::class);
    expect(User::query()->count())->toBe($countBefore);

    // --force bypasses `db:seed`'s own separate "you're in production, are you
    // sure?" confirmation prompt, so only AdminUsersSeeder's own explicit
    // environment guard is under test here.
    app()->detectEnvironment(fn () => 'production');
    User::query()->where('email', 'security@vellix.lk')->delete();
    $this->artisan('db:seed', ['--class' => AdminUsersSeeder::class, '--force' => true])->assertSuccessful();
    expect(User::query()->where('email', 'security@vellix.lk')->exists())->toBeFalse();
});
