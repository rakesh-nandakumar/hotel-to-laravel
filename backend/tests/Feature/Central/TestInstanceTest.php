<?php

use App\Models\AuditLog;
use App\Models\CentralAdmin;
use App\Models\Hotel\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    actingAsCentral(CentralAdmin::factory()->create());
});

function lookup(string $type, string $code = 'default'): int
{
    return DB::table('lookups')->insertGetId([
        'type' => $type,
        'code' => $code,
        'name' => ucfirst($code),
        'is_active' => true,
    ]);
}

/**
 * A realistic live tenant: one of everything the clone engine must remap —
 * the users↔roles cycle, pivots, polymorphic references, self-referencing
 * orders, settings and module flags.
 */
function seedLiveTenant(Tenant $tenant): array
{
    $statusOpen = lookup('order_status');
    $diningMode = lookup('dining_mode');
    $orderType = lookup('order_type');
    $kotStatus = lookup('kot_status');
    $tableStatus = lookup('table_status');
    $roomStatus = lookup('room_status');
    $reservationStatus = lookup('reservation_status');
    $channel = lookup('booking_channel');
    $folioType = lookup('folio_type');
    $folioStatus = lookup('folio_status');
    $paymentKind = lookup('payment_kind');
    $paymentMethod = lookup('payment_method');
    $lineSource = lookup('line_source');
    $tillStatus = lookup('till_status');
    $movementType = lookup('till_movement_type');
    $taskStatus = lookup('task_status');
    $checkKind = lookup('check_kind');
    $maintenanceStatus = lookup('maintenance_status');
    $listingType = lookup('listing_type');
    $unitStatus = lookup('unit_status');
    $bookingStatus = lookup('apartment_booking_status');
    $ledgerStatus = lookup('ledger_status');
    $payrollStatus = lookup('payroll_status');

    $permissionId = DB::table('permissions')->insertGetId(['name' => 'hotel.orders.access']);

    $owner = DB::table('users')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Live Owner',
        'email' => 'owner@live.test',
        'password' => 'hashed',
        'role_id' => null,
    ]);
    $role = DB::table('roles')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Full Administrator',
        'is_system' => true,
        'is_full_admin' => true,
        'created_by' => $owner,
    ]);
    DB::table('users')->where('id', $owner)->update(['role_id' => $role]);
    DB::table('user_roles')->insert(['user_id' => $owner, 'role_id' => $role]);
    DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId]);

    $staff = DB::table('users')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Live Staff',
        'email' => 'staff@live.test',
        'password' => 'hashed',
        'role_id' => $role,
    ]);

    $branch = DB::table('warehouses')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Downtown',
        'manager_user_id' => $owner,
    ]);
    DB::table('user_warehouse_access')->insert(['user_id' => $staff, 'warehouse_id' => $branch]);

    $roomType = DB::table('room_types')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Deluxe',
        'weekday_rate' => 100,
        'weekend_rate' => 120,
    ]);
    $room = DB::table('rooms')->insertGetId([
        'tenant_id' => $tenant->id,
        'number' => '101',
        'room_type_id' => $roomType,
        'branch_id' => $branch,
        'room_status_id' => $roomStatus,
    ]);
    DB::table('seasonal_rates')->insert([
        'tenant_id' => $tenant->id,
        'room_type_id' => $roomType,
        'name' => 'Peak',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'rate' => 150,
    ]);

    $guest = DB::table('guests')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'email' => 'jane@guest.test',
    ]);
    $reservation = DB::table('reservations')->insertGetId([
        'tenant_id' => $tenant->id,
        'code' => 'RES-001',
        'guest_id' => $guest,
        'booking_channel_id' => $channel,
        'reservation_status_id' => $reservationStatus,
        'check_in' => '2026-08-10',
        'check_out' => '2026-08-12',
    ]);

    $area = DB::table('dining_areas')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Main Hall']);
    $table = DB::table('dining_tables')->insertGetId([
        'tenant_id' => $tenant->id,
        'table_no' => 'T1',
        'dining_area_id' => $area,
        'table_status_id' => $tableStatus,
    ]);

    $category = DB::table('pos_menu_categories')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Mains']);
    $menuItem = DB::table('pos_menu_items')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Kottu',
        'menu_category_id' => $category,
        'price' => 900,
    ]);

    $ingredient = DB::table('ingredients')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Flour', 'unit' => 'kg']);
    DB::table('ingredient_batches')->insert([
        'tenant_id' => $tenant->id,
        'ingredient_id' => $ingredient,
        'qty' => 10,
        'initial_qty' => 10,
    ]);
    DB::table('recipe_items')->insert([
        'tenant_id' => $tenant->id,
        'menu_item_id' => $menuItem,
        'ingredient_id' => $ingredient,
        'qty' => 0.5,
    ]);

    $addOn = DB::table('add_ons')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Cheese', 'price' => 150]);
    DB::table('add_on_links')->insert([
        'tenant_id' => $tenant->id,
        'add_on_id' => $addOn,
        'menu_item_id' => $menuItem,
    ]);

    $modGroup = DB::table('menu_item_modifier_groups')->insertGetId([
        'tenant_id' => $tenant->id,
        'menu_item_id' => $menuItem,
        'name' => 'Spice',
    ]);
    $modifier = DB::table('menu_item_modifiers')->insertGetId([
        'tenant_id' => $tenant->id,
        'modifier_group_id' => $modGroup,
        'name' => 'Extra Hot',
    ]);

    $till = DB::table('tills')->insertGetId(['tenant_id' => $tenant->id, 'branch_id' => $branch, 'name' => 'Main Till']);
    $session = DB::table('till_sessions')->insertGetId([
        'tenant_id' => $tenant->id,
        'till_id' => $till,
        'status_id' => $tillStatus,
        'opened_by' => $staff,
        'opening_cash' => 5000,
    ]);

    $order = DB::table('orders')->insertGetId([
        'tenant_id' => $tenant->id,
        'order_type_id' => $orderType,
        'dining_mode_id' => $diningMode,
        'order_status_id' => $statusOpen,
        'kot_status_id' => $kotStatus,
        'dining_table_id' => $table,
        'staff_id' => $staff,
        'total' => 1050,
    ]);
    $orderItem = DB::table('order_items')->insertGetId([
        'tenant_id' => $tenant->id,
        'order_id' => $order,
        'menu_item_id' => $menuItem,
        'name' => 'Kottu',
        'qty' => 1,
        'unit_price' => 900,
        'amount' => 900,
    ]);
    DB::table('order_item_modifiers')->insert([
        'tenant_id' => $tenant->id,
        'order_item_id' => $orderItem,
        'menu_item_modifier_id' => $modifier,
        'name' => 'Extra Hot',
    ]);

    $groupBooking = DB::table('group_bookings')->insertGetId(['tenant_id' => $tenant->id, 'reference' => 'GB-1', 'name' => 'Tour Group']);
    $corporate = DB::table('corporate_accounts')->insertGetId(['tenant_id' => $tenant->id, 'company_name' => 'Acme Corp']);
    $package = DB::table('packages')->insertGetId(['tenant_id' => $tenant->id, 'code' => 'PKG-1', 'name' => 'City Break']);

    $folio = DB::table('folios')->insertGetId([
        'tenant_id' => $tenant->id,
        'folio_type_id' => $folioType,
        'folio_status_id' => $folioStatus,
        'reservation_id' => $reservation,
    ]);
    DB::table('folio_lines')->insert([
        'tenant_id' => $tenant->id,
        'folio_id' => $folio,
        'order_id' => $order,
        'line_source_id' => $lineSource,
        'description' => 'Dinner',
        'unit_price' => 1050,
        'amount' => 1050,
        'staff_id' => $staff,
    ]);
    DB::table('payments')->insert([
        'tenant_id' => $tenant->id,
        'payment_kind_id' => $paymentKind,
        'payment_method_id' => $paymentMethod,
        'amount' => 1050,
        'folio_id' => $folio,
        'staff_id' => $staff,
        'till_session_id' => $session,
    ]);

    DB::table('till_movements')->insert([
        'tenant_id' => $tenant->id,
        'till_session_id' => $session,
        'type_id' => $movementType,
        'amount' => 500,
        'source_type' => Order::class,
        'source_id' => $order,
        'performed_by' => $staff,
    ]);

    $venue = DB::table('venues')->insertGetId([
        'tenant_id' => $tenant->id,
        'name' => 'Ballroom',
        'max_capacity' => 100,
        'hourly_rate' => 500,
        'half_day_rate' => 2000,
        'full_day_rate' => 3500,
        'branch_id' => $branch,
    ]);
    $venueBooking = DB::table('venue_bookings')->insertGetId([
        'tenant_id' => $tenant->id,
        'code' => 'VB-001',
        'venue_id' => $venue,
        'guest_id' => $guest,
        'client_name' => 'Acme Events',
        'date' => '2026-09-01',
        'duration_type_id' => $orderType,
        'venue_booking_status_id' => $reservationStatus,
    ]);

    DB::table('housekeeping_tasks')->insert([
        'tenant_id' => $tenant->id,
        'room_id' => $room,
        'task_status_id' => $taskStatus,
        'checklist' => '[]',
        'assigned_to_id' => $staff,
    ]);
    DB::table('room_item_checks')->insert([
        'tenant_id' => $tenant->id,
        'reservation_id' => $reservation,
        'room_id' => $room,
        'check_kind_id' => $checkKind,
        'items' => '[]',
        'staff_id' => $staff,
    ]);
    DB::table('maintenance_issues')->insert([
        'tenant_id' => $tenant->id,
        'room_id' => $room,
        'description' => 'AC broken',
        'maintenance_status_id' => $maintenanceStatus,
        'logged_by_id' => $staff,
    ]);
    DB::table('qr_ordering_points')->insert([
        'tenant_id' => $tenant->id,
        'room_id' => $room,
        'token' => 'token-abc',
    ]);

    DB::table('loyalty_transactions')->insert([
        'tenant_id' => $tenant->id,
        'guest_id' => $guest,
        'points' => 50,
        'reason' => 'Stay',
        'staff_id' => $staff,
    ]);
    DB::table('attendances')->insert(['tenant_id' => $tenant->id, 'user_id' => $staff, 'clock_in' => '2026-08-15 09:00:00']);
    DB::table('visitor_logs')->insert(['tenant_id' => $tenant->id, 'name' => 'Visitor', 'logged_by_id' => $staff, 'time_in' => '2026-08-15 10:00:00']);
    DB::table('night_audits')->insert([
        'tenant_id' => $tenant->id,
        'business_date' => '2026-08-15',
        'data' => '{}',
        'run_by_id' => $staff,
    ]);

    $run = DB::table('payroll_runs')->insertGetId([
        'tenant_id' => $tenant->id,
        'month' => '2026-08',
        'payroll_status_id' => $payrollStatus,
        'run_by_id' => $staff,
    ]);
    DB::table('payroll_lines')->insert([
        'tenant_id' => $tenant->id,
        'run_id' => $run,
        'user_id' => $staff,
        'base_salary' => 50000,
    ]);

    DB::table('settings')->insert([
        'tenant_id' => $tenant->id,
        'key' => 'hotel.name',
        'value' => 'Live Hotel',
        'category' => 'hotel',
        'label' => 'Hotel name',
    ]);
    DB::table('tenant_modules')->insert(['tenant_id' => $tenant->id, 'module_key' => 'hotel']);

    $property = DB::table('apartment_properties')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Residences', 'branch_id' => $branch]);
    $unitType = DB::table('apartment_unit_types')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Studio']);
    $unit = DB::table('apartment_units')->insertGetId([
        'tenant_id' => $tenant->id,
        'unit_no' => 'A1',
        'property_id' => $property,
        'unit_type_id' => $unitType,
        'listing_type_id' => $listingType,
        'unit_status_id' => $unitStatus,
    ]);
    $apartmentCustomer = DB::table('apartment_customers')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Tenant Person']);
    $apartmentBooking = DB::table('apartment_bookings')->insertGetId([
        'tenant_id' => $tenant->id,
        'code' => 'APB-001',
        'unit_id' => $unit,
        'customer_id' => $apartmentCustomer,
        'booking_status_id' => $bookingStatus,
        'channel_id' => $channel,
        'check_in' => '2026-08-10',
        'check_out' => '2026-08-12',
        'nightly_rate' => 80,
    ]);
    $ledger = DB::table('apartment_ledgers')->insertGetId([
        'tenant_id' => $tenant->id,
        'ledger_status_id' => $ledgerStatus,
        'booking_id' => $apartmentBooking,
    ]);
    DB::table('apartment_ledger_lines')->insert([
        'tenant_id' => $tenant->id,
        'ledger_id' => $ledger,
        'line_source_id' => $lineSource,
        'description' => 'Night rate',
        'unit_price' => 80,
        'amount' => 160,
    ]);

    return compact(
        'owner', 'role', 'staff', 'branch', 'roomType', 'room', 'guest', 'reservation',
        'area', 'table', 'category', 'menuItem', 'ingredient', 'addOn', 'modGroup',
        'modifier', 'till', 'session', 'order', 'orderItem', 'groupBooking', 'corporate',
        'package', 'folio', 'venue', 'venueBooking', 'run', 'property', 'unitType',
        'unit', 'apartmentCustomer', 'apartmentBooking', 'ledger', 'permissionId',
    );
}

function assertTenantIsolation(array $live, Tenant $test, int $liveTenantId): void
{
    $testOwner = DB::table('users')->where('tenant_id', $test->id)->where('name', 'Live Owner')->first();
    $testStaff = DB::table('users')->where('tenant_id', $test->id)->where('name', 'Live Staff')->first();
    $testRole = DB::table('roles')->where('tenant_id', $test->id)->first();
    $testBranch = DB::table('warehouses')->where('tenant_id', $test->id)->first();
    $testRoom = DB::table('rooms')->where('tenant_id', $test->id)->first();
    $testReservation = DB::table('reservations')->where('tenant_id', $test->id)->first();
    $testOrder = DB::table('orders')->where('tenant_id', $test->id)->first();
    $testSession = DB::table('till_sessions')->where('tenant_id', $test->id)->first();
    $testFolio = DB::table('folios')->where('tenant_id', $test->id)->first();
    $testGuest = DB::table('guests')->where('tenant_id', $test->id)->first();

    // Every row moved to the test tenant.
    expect($testOwner->id)->not->toBe($live['owner']);
    expect($testRole->id)->not->toBe($live['role']);
    expect($testBranch->id)->not->toBe($live['branch']);
    expect($testRoom->id)->not->toBe($live['room']);

    // FK remapping across the users↔roles cycle and every domain edge.
    expect($testOwner->role_id)->toBe($testRole->id);
    expect((int) DB::table('roles')->where('id', $testRole->id)->value('created_by'))->toBe($testOwner->id);
    expect($testRoom->branch_id)->toBe($testBranch->id);
    expect($testRoom->room_type_id)->not->toBe($live['roomType']);
    expect($testReservation->guest_id)->toBe($testGuest->id);
    expect($testOrder->staff_id)->toBe($testStaff->id);
    expect((int) DB::table('till_movements')->where('tenant_id', $test->id)->value('source_id'))->toBe($testOrder->id);
    expect($testFolio->reservation_id)->toBe($testReservation->id);
    expect((int) DB::table('payments')->where('tenant_id', $test->id)->value('till_session_id'))->toBe($testSession->id);

    // Global lookups stay verbatim.
    $liveOrder = DB::table('orders')->where('id', $live['order'])->first();
    expect($testOrder->order_type_id)->toBe($liveOrder->order_type_id);

    // Pivots are remapped; global permission ids stay verbatim.
    $testUserRoles = DB::table('user_roles')->where('user_id', $testOwner->id)->first();
    expect($testUserRoles->role_id)->toBe($testRole->id);
    $testRolePermission = DB::table('role_permissions')->where('role_id', $testRole->id)->first();
    expect($testRolePermission->permission_id)->toBe($live['permissionId']);
    $testAccess = DB::table('user_warehouse_access')->where('user_id', $testStaff->id)->first();
    expect($testAccess->warehouse_id)->toBe($testBranch->id);

    // Settings and module flags copied.
    expect(DB::table('settings')->where('tenant_id', $test->id)->where('key', 'hotel.name')->value('value'))->toBe('Live Hotel');
    expect(DB::table('tenant_modules')->where('tenant_id', $test->id)->where('module_key', 'hotel')->exists())->toBeTrue();
}

it('creates a test instance with fully remapped business data', function () {
    $live = Tenant::factory()->create(['slug' => 'demo-live']);
    $ids = seedLiveTenant($live);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")
        ->dump()
        ->assertCreated()
        ->assertJsonPath('instance.slug', 'demo-live-test')
        ->assertJsonPath('instance.environment', 'test')
        ->assertJsonPath('instance.parent_tenant_id', $live->id)
        ->assertJsonPath('instance.status', 'active');

    $test = Tenant::query()->where('slug', 'demo-live-test')->first();
    expect($test)->not->toBeNull();

    assertTenantIsolation($ids, $test, $live->id);

    foreach ([
        'users', 'roles', 'warehouses', 'rooms', 'guests', 'reservations', 'orders',
        'order_items', 'tills', 'till_sessions', 'till_movements', 'folios', 'folio_lines',
        'payments', 'venues', 'venue_bookings', 'night_audits', 'payroll_runs',
        'payroll_lines', 'settings', 'tenant_modules', 'apartment_properties',
        'apartment_units', 'apartment_bookings', 'apartment_ledgers',
    ] as $table) {
        $liveCount = DB::table($table)->where('tenant_id', $live->id)->count();
        $testCount = DB::table($table)->where('tenant_id', $test->id)->count();
        expect($testCount)->toBe($liveCount, "{$table}: live {$liveCount}, test {$testCount}");
    }

    // No cross-tenant leakage: live rows untouched.
    expect(DB::table('users')->where('id', $ids['owner'])->value('tenant_id'))->toBe($live->id);

    // The central action is stamped on the live tenant's audit trail.
    expect(AuditLog::query()->withoutTenantScope()
        ->where('tenant_id', $live->id)
        ->where('action', 'test_instance.created')
        ->exists())->toBeTrue();
});

it('rejects a second test instance and one off a test instance', function () {
    $live = Tenant::factory()->create(['slug' => 'second']);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")
        ->assertCreated();

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")
        ->assertStatus(422);

    $other = Tenant::factory()->create(['slug' => 'other']);
    Tenant::factory()->create(['slug' => 'other-test', 'environment' => 'test', 'parent_tenant_id' => $other->id]);

    $this->postJson("http://admin.localhost/api/central/tenants/{$other->id}/test-instance")
        ->assertStatus(422);
});

it('rejects a test instance when the -test subdomain is taken', function () {
    $live = Tenant::factory()->create(['slug' => 'taken-live']);
    Tenant::factory()->create(['slug' => 'taken-live-test']);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")
        ->assertStatus(422);
});

it('syncs a test instance from live, atomically replacing its data', function () {
    $live = Tenant::factory()->create(['slug' => 'syncer']);
    seedLiveTenant($live);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")->assertCreated();
    $test = Tenant::query()->where('slug', 'syncer-test')->first();

    $oldTestRoomId = DB::table('rooms')->where('tenant_id', $test->id)->value('id');
    $oldTestOrderId = DB::table('orders')->where('tenant_id', $test->id)->value('id');

    // Live changes after the instance was created.
    $liveRoomType = DB::table('rooms')->where('tenant_id', $live->id)->value('room_type_id');
    DB::table('rooms')->insert([
        'tenant_id' => $live->id,
        'number' => '202',
        'room_type_id' => $liveRoomType,
        'branch_id' => DB::table('warehouses')->where('tenant_id', $live->id)->value('id'),
        'room_status_id' => DB::table('lookups')->where('type', 'room_status')->value('id'),
    ]);
    DB::table('orders')->where('tenant_id', $live->id)->update(['total' => 9999]);

    $response = $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance/sync")
        ->assertOk()
        ->assertJsonPath('instance.last_synced_at', $test->refresh()->last_synced_at?->toISOString());

    expect($response->json('summary.total_rows'))->toBeGreaterThan(0);

    // The wiped rows got fresh ids; live rows untouched; counts match.
    expect(DB::table('rooms')->where('id', $oldTestRoomId)->exists())->toBeFalse();
    expect(DB::table('orders')->where('id', $oldTestOrderId)->exists())->toBeFalse();
    expect(DB::table('rooms')->where('tenant_id', $test->id)->count())
        ->toBe(DB::table('rooms')->where('tenant_id', $live->id)->count());
    expect(DB::table('orders')->where('tenant_id', $test->id)->value('total'))->toBe(9999);

    expect(AuditLog::query()->withoutTenantScope()
        ->where('tenant_id', $live->id)
        ->where('action', 'test_instance.synced')
        ->exists())->toBeTrue();
});

it('destroys a test instance without touching the live tenant', function () {
    $live = Tenant::factory()->create(['slug' => 'doomed']);
    seedLiveTenant($live);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")->assertCreated();
    $test = Tenant::query()->where('slug', 'doomed-test')->first();

    $this->deleteJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")
        ->assertOk();

    expect(Tenant::query()->withTrashed()->find($test->id))->toBeNull();
    expect(DB::table('users')->where('tenant_id', $test->id)->count())->toBe(0);
    expect(DB::table('orders')->where('tenant_id', $test->id)->count())->toBe(0);
    expect(DB::table('rooms')->where('tenant_id', $test->id)->count())->toBe(0);

    // The live tenant is untouched and can spawn a fresh instance again.
    expect(DB::table('users')->where('tenant_id', $live->id)->count())->toBe(2);
    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")->assertCreated();
});

it('lists all test instances with their live parent', function () {
    $live = Tenant::factory()->create(['slug' => 'listed']);
    seedLiveTenant($live);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")->assertCreated();

    $this->getJson('http://admin.localhost/api/central/test-instances')
        ->assertOk()
        ->assertJsonCount(1, 'instances')
        ->assertJsonPath('instances.0.environment', 'test')
        ->assertJsonPath('instances.0.parent_tenant.slug', 'listed');
});

it('shows a tenants test instance in tenant detail', function () {
    $live = Tenant::factory()->create(['slug' => 'showable']);
    seedLiveTenant($live);

    $this->postJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")->assertCreated();

    $this->getJson("http://admin.localhost/api/central/tenants/{$live->id}/test-instance")
        ->assertOk()
        ->assertJsonPath('instance.slug', 'showable-test');
});
