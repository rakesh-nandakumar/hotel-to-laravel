<?php

use App\Models\Apartment\Booking as ApartmentBooking;
use App\Models\Apartment\Customer as ApartmentCustomer;
use App\Models\Apartment\Property as ApartmentProperty;
use App\Models\Apartment\Unit as ApartmentUnit;
use App\Models\AuditLog;
use App\Models\CentralAdmin;
use App\Models\DeviceToken;
use App\Models\Hotel\AddOn;
use App\Models\Hotel\DiningArea;
use App\Models\Hotel\DiningTable;
use App\Models\Hotel\Folio;
use App\Models\Hotel\GroupBooking;
use App\Models\Hotel\Guest;
use App\Models\Hotel\HousekeepingTask;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\LaundryItem;
use App\Models\Hotel\MaintenanceIssue;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\NightAudit;
use App\Models\Hotel\Order;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\Package;
use App\Models\Hotel\Payment;
use App\Models\Hotel\PayrollLine;
use App\Models\Hotel\PayrollRun;
use App\Models\Hotel\QrOrderingPoint;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Hotel\Venue;
use App\Models\Hotel\VenueBooking;
use App\Models\Lookup;
use App\Models\MenuItem as NavMenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scopes\TenantScope;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\Till;
use App\Models\TillMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Services\CurrentContext;
use App\Support\ModuleCatalog;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    // The API-adjacent assertions here ride absolute tenant URLs — the
    // TestCase's default X-Tenant-Slug header would override them.
    $this->withoutHeader('X-Tenant-Slug');
});

/**
 * Regression suite for the tenant-isolation work on the business-domain
 * tables (guests, customers, reservations, orders, menu items, …). Until the
 * 2026_08_16_000001 migration and the BelongsToTenant trait sweep, these
 * tables carried no tenant column and no scope, so every tenant user could
 * read every tenant's data.
 *
 * Tests run in console, where TenantScope is intentionally bypassed — so the
 * behavioral tests here wrap their assertions in
 * CurrentContext::simulateWebRequest(), exactly like TenantScopeTest.
 */

/**
 * Structural guarantee: every domain model boots with the tenant scope — a
 * model added later without the trait fails this test instead of silently
 * leaking data.
 */
it('boots every domain model with its tenant scope', function () {
    $tenantScoped = [
        Guest::class,
        Room::class,
        Venue::class,
        ApartmentProperty::class,
        Till::class,
        RoomType::class,
        Package::class,
        Reservation::class,
        Folio::class,
        Payment::class,
        Order::class,
        OrderItem::class,
        MenuCategory::class,
        MenuItem::class,
        Ingredient::class,
        DiningArea::class,
        DiningTable::class,
        LaundryItem::class,
        VenueBooking::class,
        GroupBooking::class,
        HousekeepingTask::class,
        MaintenanceIssue::class,
        NightAudit::class,
        PayrollRun::class,
        PayrollLine::class,
        QrOrderingPoint::class,
        AddOn::class,
        ApartmentCustomer::class,
        ApartmentUnit::class,
        ApartmentBooking::class,
        AuditLog::class,
        DeviceToken::class,
        TillSession::class,
        TillMovement::class,
        User::class,
        Role::class,
        Setting::class,
    ];

    foreach ($tenantScoped as $model) {
        expect((new $model)->getGlobalScopes())->toHaveKey(TenantScope::class)
            ->and($model)->toBe($model);
    }
});

it('leaves the global catalogs and central tables unscoped', function () {
    $global = [Lookup::class, Permission::class, NavMenuItem::class, Tenant::class, CentralAdmin::class];

    foreach ($global as $model) {
        expect((new $model)->getGlobalScopes())->not->toHaveKey(TenantScope::class);
    }

    // TenantModule is queried with an explicit tenant_id — no global scope.
    expect((new TenantModule)->getGlobalScopes())->not->toHaveKey(TenantScope::class);
});

it('isolates every newly-scoped domain table to the resolved tenant', function () {
    $tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $tenantB = Tenant::factory()->create(['slug' => 'beta']);

    $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

    Guest::create(['tenant_id' => $tenantA->id, 'name' => 'Guest A']);
    Guest::create(['tenant_id' => $tenantB->id, 'name' => 'Guest B']);

    RoomType::create([
        'tenant_id' => $tenantA->id,
        'name' => 'Type A',
        'weekday_rate' => 1000,
        'weekend_rate' => 1200,
    ]);
    RoomType::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Type B',
        'weekday_rate' => 2000,
        'weekend_rate' => 2200,
    ]);

    ApartmentCustomer::create(['tenant_id' => $tenantA->id, 'name' => 'Cust A']);
    ApartmentCustomer::create(['tenant_id' => $tenantB->id, 'name' => 'Cust B']);

    AuditLog::create(['tenant_id' => $tenantA->id, 'actor_id' => $userA->id, 'action' => 'a', 'context' => []]);
    AuditLog::create(['tenant_id' => $tenantB->id, 'actor_id' => $userB->id, 'action' => 'b', 'context' => []]);
    AuditLog::create(['action' => 'central', 'context' => []]);

    DeviceToken::create(['tenant_id' => $tenantA->id, 'user_id' => $userA->id, 'token_hash' => Str::random(64), 'expires_at' => now()->addDay()]);
    DeviceToken::create(['tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'token_hash' => Str::random(64), 'expires_at' => now()->addDay()]);

    Till::create(['tenant_id' => $tenantA->id, 'name' => 'Till A']);
    Till::create(['tenant_id' => $tenantB->id, 'name' => 'Till B']);

    CurrentContext::simulateWebRequest(function () use ($tenantA, $tenantB) {
        app(CurrentContext::class)->setTenant($tenantA->id);

        expect(Guest::query()->pluck('name')->all())->toBe(['Guest A']);
        expect(RoomType::query()->pluck('name')->all())->toBe(['Type A']);
        expect(ApartmentCustomer::query()->pluck('name')->all())->toBe(['Cust A']);
        expect(DeviceToken::query()->pluck('token_hash')->count())->toBe(1);
        expect(AuditLog::query()->pluck('action')->all())->toBe(['a']);
        expect(Till::query()->pluck('name')->all())->toBe(['Till A']);

        app(CurrentContext::class)->setTenant($tenantB->id);

        expect(Guest::query()->pluck('name')->all())->toBe(['Guest B']);
        expect(RoomType::query()->pluck('name')->all())->toBe(['Type B']);
        expect(ApartmentCustomer::query()->pluck('name')->all())->toBe(['Cust B']);
        expect(AuditLog::query()->pluck('action')->all())->toBe(['b']);
        expect(Till::query()->pluck('name')->all())->toBe(['Till B']);
    });
});

/**
 * Regression coverage for the branch-dimension removal: Room, Venue,
 * ApartmentProperty and Till used to be scoped only by BranchScope (with no
 * BelongsToTenant of their own — tenant isolation came transitively through
 * their branch). Now that branches are gone, they carry BelongsToTenant
 * directly; this proves tenant B can never see tenant A's rows in any of them.
 */
it('isolates the former branch-scoped models directly by tenant', function () {
    $tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $tenantB = Tenant::factory()->create(['slug' => 'beta']);

    $roomStatusId = Lookup::create(['type' => 'room_status', 'code' => 'available', 'name' => 'Available', 'is_active' => true])->id;

    Room::create(['tenant_id' => $tenantA->id, 'number' => '101', 'room_status_id' => $roomStatusId]);
    Room::create(['tenant_id' => $tenantB->id, 'number' => '101', 'room_status_id' => $roomStatusId]);

    Venue::create([
        'tenant_id' => $tenantA->id, 'name' => 'Ballroom A', 'max_capacity' => 50,
        'hourly_rate' => 100, 'half_day_rate' => 400, 'full_day_rate' => 700,
    ]);
    Venue::create([
        'tenant_id' => $tenantB->id, 'name' => 'Ballroom B', 'max_capacity' => 50,
        'hourly_rate' => 100, 'half_day_rate' => 400, 'full_day_rate' => 700,
    ]);

    ApartmentProperty::create(['tenant_id' => $tenantA->id, 'name' => 'Property A']);
    ApartmentProperty::create(['tenant_id' => $tenantB->id, 'name' => 'Property B']);

    Till::create(['tenant_id' => $tenantA->id, 'name' => 'Till A']);
    Till::create(['tenant_id' => $tenantB->id, 'name' => 'Till B']);

    CurrentContext::simulateWebRequest(function () use ($tenantA, $tenantB) {
        app(CurrentContext::class)->setTenant($tenantA->id);

        expect(Room::query()->count())->toBe(1);
        expect(Venue::query()->pluck('name')->all())->toBe(['Ballroom A']);
        expect(ApartmentProperty::query()->pluck('name')->all())->toBe(['Property A']);
        expect(Till::query()->pluck('name')->all())->toBe(['Till A']);

        app(CurrentContext::class)->setTenant($tenantB->id);

        expect(Room::query()->count())->toBe(1);
        expect(Venue::query()->pluck('name')->all())->toBe(['Ballroom B']);
        expect(ApartmentProperty::query()->pluck('name')->all())->toBe(['Property B']);
        expect(Till::query()->pluck('name')->all())->toBe(['Till B']);
    });
});

it('auto-stamps tenant_id on create from the resolved tenant context', function () {
    $tenant = Tenant::factory()->create();

    CurrentContext::simulateWebRequest(function () use ($tenant) {
        app(CurrentContext::class)->setTenant($tenant->id);

        expect(Guest::create(['name' => 'Walk-in'])->tenant_id)->toBe($tenant->id);
        expect(RoomType::create(['name' => 'Stamped', 'weekday_rate' => 1, 'weekend_rate' => 2])->tenant_id)->toBe($tenant->id);
        expect(AuditLog::create(['action' => 'stamped', 'context' => []])->tenant_id)->toBe($tenant->id);
    });
});

it('fails closed when no tenant is resolved', function () {
    Tenant::factory()->create();

    Guest::create(['name' => 'Orphan']);

    CurrentContext::simulateWebRequest(function () {
        app(CurrentContext::class)->setTenant(null);

        expect(Guest::query()->count())->toBe(0);
        expect(RoomType::query()->count())->toBe(0);
        expect(ApartmentCustomer::query()->count())->toBe(0);
        expect(AuditLog::query()->count())->toBe(0);
    });
});

it('keeps the central-admin escape hatch working for domain rows', function () {
    $tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $tenantB = Tenant::factory()->create(['slug' => 'beta']);

    Guest::create(['tenant_id' => $tenantA->id, 'name' => 'Guest A']);
    Guest::create(['tenant_id' => $tenantB->id, 'name' => 'Guest B']);

    expect(Guest::query()->withoutTenantScope()->count())->toBe(2);
});

it('a tenant user only sees their own users and guests through the API', function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);

    $tenantA = Tenant::factory()->create(['slug' => 'acme']);
    $tenantB = Tenant::factory()->create(['slug' => 'globex']);

    app(PermissionsAndRolesSeeder::class)->seedSystemRoles($tenantA->id);
    app(PermissionsAndRolesSeeder::class)->seedSystemRoles($tenantB->id);

    $roleA = Role::query()->withoutTenantScope()
        ->where('tenant_id', $tenantA->id)
        ->where('is_full_admin', true)
        ->first();

    $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'email' => 'a@acme.test']);
    $userA->roles()->sync([$roleA->id]);
    $userA->flushPermissionCache();

    User::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'b@globex.test']);
    User::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'b2@globex.test']);

    Guest::create(['tenant_id' => $tenantA->id, 'name' => 'Guest of Acme']);
    Guest::create(['tenant_id' => $tenantB->id, 'name' => 'Guest of Globex']);
    Guest::create(['tenant_id' => $tenantB->id, 'name' => 'Guest of Globex 2']);

    TenantModule::create(['tenant_id' => $tenantA->id, 'module_key' => ModuleCatalog::HOTEL_OPERATIONS, 'is_enabled' => true]);

    CurrentContext::simulateWebRequest(function () {
        // Tenancy identity rides the prefix header now (the SPA at /acme/…
        // reads it from its own path) — the requests below wrap it like
        // every tenant request does.
        $this->postJson('/api/login', [
            'email' => 'a@acme.test',
            'password' => 'password',
        ], ['X-Tenant-Slug' => 'acme'])->assertOk();

        $users = $this->getJson('/api/user-management/users', ['X-Tenant-Slug' => 'acme'])->assertOk()->json('users');
        expect($users['total'])->toBe(1);
        expect(array_column($users['data'], 'email'))->toBe(['a@acme.test']);

        $guests = $this->getJson('/api/guests', ['X-Tenant-Slug' => 'acme'])->assertOk()->json('guests');
        expect(array_column($guests, 'name'))->toBe(['Guest of Acme']);
    });

    Auth::logout();
});
