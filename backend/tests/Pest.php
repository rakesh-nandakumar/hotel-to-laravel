<?php

use App\Models\Branch;
use App\Models\CentralAdmin;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Services\TillService;
use Database\Seeders\Menu\SystemRoleDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

    // Every test user belongs to the same demo tenant IdentifyTenant's local/testing
    // dev-fallback resolves to (the single tenant in the DB) — see config/tenancy.php
    // and App\Http\Middleware\IdentifyTenant. A mismatched tenant_id here would get
    // the user logged straight back out by that middleware's cross-tenant guard.
    $user = User::factory()->create(['tenant_id' => Tenant::demo()->id]);
    $user->roles()->syncWithoutDetaching([$role->id]);
    $user->flushPermissionCache();

    return $user;
}

/**
 * A staff user holding one of the real seeded {@see SystemRoleDefinition}
 * roles (e.g. "Manager", "Housekeeper"). Requires MenuSeeder + PermissionsAndRolesSeeder
 * to have been seeded first. Use to test real per-role permission grants, as opposed to
 * fullAdmin() which bypasses every check.
 */
function staffWithRole(string $roleName): User
{
    $role = Role::query()->where('name', $roleName)->firstOrFail();
    $user = User::factory()->create(['tenant_id' => Tenant::demo()->id]);
    $user->roles()->sync([$role->id]);
    $user->flushPermissionCache();

    return $user;
}

/**
 * Opens a till session for the given staff member — every cash payment or
 * refund now requires one (see BillingService/ApartmentBillingService's
 * till guard). Auto-creates a Branch + Till if the test hasn't seeded one,
 * so callers that don't otherwise care about branches/tills can ignore this.
 */
function openTillFor(User $staff, int $openingBalance = 100_000_000): TillSession
{
    $tillId = Till::query()->value('id');
    if (! $tillId) {
        $branchId = Branch::query()->value('id')
            ?? Branch::create(['tenant_id' => Tenant::demo()->id, 'name' => 'Main Branch', 'is_active' => true, 'country' => 'Sri Lanka'])->id;
        $tillId = Till::create(['branch_id' => $branchId, 'name' => 'Main Till'])->id;
    }

    return app(TillService::class)->openTill($tillId, $staff->id, $openingBalance);
}

/**
 * Authenticates a CentralAdmin for the test, WITHOUT using $this->actingAs()
 * — that helper also calls Auth::shouldUse($guard), which repoints every
 * *ambiguous* default-guard resolution (Auth::id(), $request->user()) at
 * 'central' for the rest of the test. Real production code never calls
 * shouldUse() itself (Laravel's own default guard is always 'web', per
 * config/auth.php), so that side effect is a test-only artifact that would
 * make Auth::id() calls elsewhere in the app (e.g. AuditLog::record()'s
 * fallback) resolve a CentralAdmin id where they're written assuming a
 * `users` row. Setting the guard's user directly sidesteps that.
 */
function actingAsCentral(CentralAdmin $admin): void
{
    Auth::guard('central')->setUser($admin);
}
