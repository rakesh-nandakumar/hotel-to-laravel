<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A user who bypasses every permission check (full-admin role).
 * Use in feature tests that exercise gated routes.
 */
function fullAdmin(): User
{
    $role = Role::firstOrCreate(
        ['name' => 'Test Full Admin'],
        ['is_full_admin' => true, 'is_active' => true, 'is_system' => false],
    );

    $user = User::factory()->create();
    $user->roles()->syncWithoutDetaching([$role->id]);
    $user->flushPermissionCache();

    return $user;
}

/**
 * A staff user holding one of the real seeded {@see \Database\Seeders\Menu\SystemRoleDefinition}
 * roles (e.g. "Manager", "Housekeeper"). Requires MenuSeeder + PermissionsAndRolesSeeder
 * to have been seeded first. Use to test real per-role permission grants, as opposed to
 * fullAdmin() which bypasses every check.
 */
function staffWithRole(string $roleName): User
{
    $role = Role::query()->where('name', $roleName)->firstOrFail();
    $user = User::factory()->create();
    $user->roles()->sync([$role->id]);
    $user->flushPermissionCache();

    return $user;
}
