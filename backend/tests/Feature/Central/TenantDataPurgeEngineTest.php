<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\DataPurgeService;
use App\Services\Tenancy\DataPurge\PurgeAbortedException;
use App\Services\Tenancy\DataPurge\PurgeCaches;
use App\Services\Tenancy\DataPurge\PurgeConflictException;
use App\Services\Tenancy\DataPurge\PurgeGraph;
use App\Services\Tenancy\DataPurge\PurgePlanner;
use App\Services\Tenancy\DataPurge\TenantStateReconciler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PurgeFixtures;
use Tests\Support\PurgeScenarios as S;

beforeEach(function () {
    Storage::fake('local');
    $this->withoutHeader('X-Tenant-Slug');
    $this->admin = CentralAdmin::factory()->create();
    actingAsCentral($this->admin);

    $this->f = new PurgeFixtures;
    $this->tenant = Tenant::factory()->create();
    $this->other = Tenant::factory()->create();
});

/*
|--------------------------------------------------------------------------
| Isolation and guards
|--------------------------------------------------------------------------
*/

it('purges every category of a fully populated tenant and touches nothing else', function () {
    $graph = app(PurgeGraph::class);

    $this->f->populate($this->tenant->id);
    $this->f->populate($this->other->id);

    // Operations legitimately add history rows for this tenant; everything else must be untouched.
    $protected = array_values(array_diff($graph->tenantTables(), $graph->scopeTables(), ['audit_logs', 'tenant_data_purges']));
    $protectedBefore = Arr::only(S::counts($this->tenant->id), $protected);
    $otherBefore = S::dump($this->other->id, $graph->tenantTables());

    $purge = S::purge($this->tenant, $this->admin, array_keys(config('tenancy-purge.categories')));

    $leftover = array_filter(Arr::only(S::counts($this->tenant->id), $graph->scopeTables()));

    expect($leftover)->toBe([])
        ->and(Arr::only(S::counts($this->tenant->id), $protected))->toBe($protectedBefore)
        ->and(S::dump($this->other->id, $graph->tenantTables()))->toEqual($otherBefore)
        ->and($purge->status)->toBe(TenantDataPurge::STATUS_COMPLETED)
        ->and($purge->total_rows)->toBeGreaterThan(0);
});

it('refuses to purge when a row of another tenant hangs off the selection', function () {
    $stay = S::stay($this->f, $this->tenant->id);
    $foreignLine = $this->f->row('folio_lines', $this->other->id, ['folio_id' => $stay['folio']]);
    $before = S::dump($this->tenant->id);

    expect(fn () => S::purge($this->tenant, $this->admin, ['hotel_reservations']))
        ->toThrow(PurgeAbortedException::class, 'another tenant');

    expect(S::dump($this->tenant->id))->toEqual($before)
        ->and(S::exists('folio_lines', $foreignLine))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(TenantDataPurge::query()->count())->toBe(0);
});

it('refuses to run when a protected table would be touched', function () {
    config(['tenancy-purge.protected' => [...config('tenancy-purge.protected'), 'folio_lines']]);

    S::stay($this->f, $this->tenant->id);

    expect(fn () => S::plan($this->tenant, ['hotel_reservations']))
        ->toThrow(PurgeAbortedException::class, 'refuses to run');
});

it('refuses an empty selection instead of recording a purge that deleted nothing', function () {
    expect(fn () => S::purge($this->tenant, $this->admin, ['hotel_reservations']))
        ->toThrow(PurgeAbortedException::class, 'nothing to delete');

    expect(TenantDataPurge::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What a category takes with it
|--------------------------------------------------------------------------
*/

it('deletes a guest with their stays, invoices, payments and loyalty entries — and nobody else’s', function () {
    $a = S::stay($this->f, $this->tenant->id, ['guest_created' => '2026-01-05 10:00:00']);
    $b = S::stay($this->f, $this->tenant->id, ['guest_created' => '2026-02-05 10:00:00', 'code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    S::purge($this->tenant, $this->admin, ['hotel_guests'], ['hotel_guests' => ['created' => ['from' => '2026-01-01', 'to' => '2026-01-31']]]);

    expect(S::stayAlive($a))->toBe(S::stayState(false))
        ->and(S::stayAlive($b))->toBe(S::stayState(true))
        ->and(S::exists('rooms', $a['room']))->toBeTrue();
});

it('purges only what matches a date filter, together with its dependents', function () {
    $march = S::stay($this->f, $this->tenant->id, ['check_in' => '2026-03-10']);
    $april = S::stay($this->f, $this->tenant->id, ['check_in' => '2026-04-10', 'code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], ['hotel_reservations' => ['check_in' => ['from' => '2026-03-01', 'to' => '2026-03-31']]]);

    // The guest is not part of a reservation purge, so it stays.
    expect(S::stayAlive($march))->toBe([...S::stayState(false), 'guests' => true])
        ->and(S::stayAlive($april))->toBe(S::stayState(true));
});

it('purges only what matches a status filter', function () {
    $cancelled = $this->f->lookup('reservation_status', 'cancelled');
    $confirmed = $this->f->lookup('reservation_status', 'confirmed');

    $gone = S::stay($this->f, $this->tenant->id, ['status' => $cancelled]);
    $kept = S::stay($this->f, $this->tenant->id, ['status' => $confirmed, 'code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], ['hotel_reservations' => ['status' => [$cancelled]]]);

    expect(S::exists('reservations', $gone['reservation']))->toBeFalse()
        ->and(S::exists('folios', $gone['folio']))->toBeFalse()
        ->and(S::stayAlive($kept))->toBe(S::stayState(true));
});

it('removes restaurant charges from a kept room invoice without touching the invoice', function () {
    $stay = S::stay($this->f, $this->tenant->id);
    $order = $this->f->row('orders', $this->tenant->id, ['reservation_id' => $stay['reservation']]);
    $item = $this->f->row('order_items', $this->tenant->id, ['order_id' => $order]);
    $orderLine = $this->f->row('folio_lines', $this->tenant->id, ['folio_id' => $stay['folio'], 'order_id' => $order]);
    $orderPayment = $this->f->row('payments', $this->tenant->id, ['order_id' => $order]);

    $plan = S::plan($this->tenant, ['restaurant_orders']);
    S::purge($this->tenant, $this->admin, ['restaurant_orders']);

    expect(collect($plan->warnings)->contains(fn (string $warning): bool => str_contains($warning, 'invoice(s) that are kept')))->toBeTrue()
        ->and([S::exists('orders', $order), S::exists('order_items', $item), S::exists('folio_lines', $orderLine), S::exists('payments', $orderPayment)])
        ->toBe([false, false, false, false])
        ->and(S::stayAlive($stay))->toBe(S::stayState(true));
});

it('deletes a whole order when one item of it is purged, never a fragment', function () {
    $category = $this->f->row('pos_menu_categories', $this->tenant->id);
    $itemA = $this->f->row('pos_menu_items', $this->tenant->id, ['menu_category_id' => $category]);
    $itemB = $this->f->row('pos_menu_items', $this->tenant->id, ['menu_category_id' => $category]);
    $order = $this->f->row('orders', $this->tenant->id);
    $lineA = $this->f->row('order_items', $this->tenant->id, ['order_id' => $order, 'menu_item_id' => $itemA]);
    $lineB = $this->f->row('order_items', $this->tenant->id, ['order_id' => $order, 'menu_item_id' => $itemB]);

    $closure = app(PurgePlanner::class)->closure($this->tenant->id, ['pos_menu_items' => [$itemA]]);

    expect($closure['pos_menu_items'])->toBe([$itemA])
        ->and($closure['orders'])->toBe([$order])
        ->and($closure['order_items'])->toBe([$lineA, $lineB]);
});

it('deletes the stays of a purged room but not the stays in other rooms', function () {
    $a = S::stay($this->f, $this->tenant->id);
    $b = S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    $closure = app(PurgePlanner::class)->closure($this->tenant->id, ['rooms' => [$a['room']]]);

    expect($closure['rooms'])->toBe([$a['room']])
        ->and($closure['reservations'])->toBe([$a['reservation']])
        ->and($closure['folios'])->toBe([$a['folio']])
        ->and(in_array($b['reservation'], $closure['reservations'], true))->toBeFalse();
});

it('deletes a lease with its invoice, rent charges, readings and payments, but no other booking', function () {
    $t = $this->tenant->id;
    $unit = $this->f->row('apartment_units', $t);
    $customer = $this->f->row('apartment_customers', $t);
    $lease = $this->f->row('apartment_leases', $t, ['unit_id' => $unit, 'customer_id' => $customer, 'code' => 'LSE-0001']);
    $ledger = $this->f->row('apartment_ledgers', $t, ['lease_id' => $lease, 'invoice_no' => 'APT-INV-2026-0001']);
    $ledgerLine = $this->f->row('apartment_ledger_lines', $t, ['ledger_id' => $ledger]);
    $rent = $this->f->row('apartment_lease_rent_charges', $t, ['lease_id' => $lease, 'ledger_line_id' => $ledgerLine]);
    $reading = $this->f->row('apartment_utility_readings', $t, ['lease_id' => $lease]);
    $payment = $this->f->row('apartment_payments', $t, ['ledger_id' => $ledger]);
    $booking = $this->f->row('apartment_bookings', $t, ['unit_id' => $unit, 'customer_id' => $customer, 'code' => 'APT-0001']);
    $bookingLedger = $this->f->row('apartment_ledgers', $t, ['booking_id' => $booking, 'invoice_no' => 'APT-INV-2026-0002']);

    S::purge($this->tenant, $this->admin, ['apartment_leases']);

    expect([
        S::exists('apartment_leases', $lease), S::exists('apartment_ledgers', $ledger), S::exists('apartment_ledger_lines', $ledgerLine),
        S::exists('apartment_lease_rent_charges', $rent), S::exists('apartment_utility_readings', $reading), S::exists('apartment_payments', $payment),
    ])->toBe([false, false, false, false, false, false])
        ->and([S::exists('apartment_units', $unit), S::exists('apartment_customers', $customer), S::exists('apartment_bookings', $booking), S::exists('apartment_ledgers', $bookingLedger)])
        ->toBe([true, true, true, true]);
});

/*
|--------------------------------------------------------------------------
| Cash ledger
|--------------------------------------------------------------------------
*/

it('keeps the cash ledger when the payment behind a movement is deleted', function () {
    $stay = S::stay($this->f, $this->tenant->id);
    $session = $this->f->row('till_sessions', $this->tenant->id);
    $movement = $this->f->row('till_movements', $this->tenant->id, [
        'till_session_id' => $session,
        'source_type' => 'App\\Models\\Hotel\\Payment',
        'source_id' => $stay['payment'],
    ]);

    S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    $row = DB::table('till_movements')->where('id', $movement)->first();

    expect($row)->not->toBeNull()
        ->and($row->source_type)->toBeNull()
        ->and($row->source_id)->toBeNull()
        ->and(S::exists('payments', $stay['payment']))->toBeFalse();
});

it('deletes till sessions with their movements, keeps the tills, and unlinks payments that stay', function () {
    $stay = S::stay($this->f, $this->tenant->id);
    $session = $this->f->row('till_sessions', $this->tenant->id);
    $movement = $this->f->row('till_movements', $this->tenant->id, ['till_session_id' => $session]);
    DB::table('payments')->where('id', $stay['payment'])->update(['till_session_id' => $session]);

    S::purge($this->tenant, $this->admin, ['till_sessions']);

    expect([S::exists('till_sessions', $session), S::exists('till_movements', $movement)])->toBe([false, false])
        ->and(DB::table('tills')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(DB::table('payments')->where('id', $stay['payment'])->value('till_session_id'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Keeping what is left consistent
|--------------------------------------------------------------------------
*/

it('puts a room back to Available only when the purge removed what justified its status', function () {
    $occupied = $this->f->lookup('room_status', 'occupied');
    $this->f->lookup('room_status', 'available');
    $checkedIn = $this->f->lookup('reservation_status', 'checked_in');

    $stayRoom = $this->f->row('rooms', $this->tenant->id, ['room_status_id' => $occupied]);
    $markedByHand = $this->f->row('rooms', $this->tenant->id, ['room_status_id' => $occupied]);
    S::stay($this->f, $this->tenant->id, ['room' => $stayRoom, 'status' => $checkedIn]);

    S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    $status = fn (int $room): string => DB::table('lookups')->where('id', DB::table('rooms')->where('id', $room)->value('room_status_id'))->value('code');

    expect($status($stayRoom))->toBe('available')
        ->and($status($markedByHand))->toBe('occupied');
});

it('keeps a room occupied while another checked-in stay still justifies it', function () {
    $occupied = $this->f->lookup('room_status', 'occupied');
    $this->f->lookup('room_status', 'available');
    $checkedIn = $this->f->lookup('reservation_status', 'checked_in');
    $cancelled = $this->f->lookup('reservation_status', 'cancelled');

    $room = $this->f->row('rooms', $this->tenant->id, ['room_status_id' => $occupied]);
    S::stay($this->f, $this->tenant->id, ['room' => $room, 'status' => $cancelled]);
    S::stay($this->f, $this->tenant->id, ['room' => $room, 'status' => $checkedIn, 'code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], ['hotel_reservations' => ['status' => [$cancelled]]]);

    expect(DB::table('rooms')->where('id', $room)->value('room_status_id'))->toBe($occupied);
});

it('takes back the loyalty points and lifetime spend that deleted records earned', function () {
    $guest = $this->f->row('guests', $this->tenant->id, ['loyalty_points' => 100, 'lifetime_spend' => 500000]);
    S::stay($this->f, $this->tenant->id, ['guest' => $guest, 'points' => 40]);
    $this->f->row('loyalty_transactions', $this->tenant->id, ['guest_id' => $guest, 'points' => 60]);

    S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    $row = DB::table('guests')->where('id', $guest)->first();

    expect($row->loyalty_points)->toBe(60)
        ->and($row->lifetime_spend)->toBe(0);
});

it('leaves lifetime spend alone while the guest still has other stays', function () {
    $guest = $this->f->row('guests', $this->tenant->id, ['loyalty_points' => 50, 'lifetime_spend' => 500000]);
    S::stay($this->f, $this->tenant->id, ['guest' => $guest, 'points' => 20, 'check_in' => '2026-03-10']);
    S::stay($this->f, $this->tenant->id, ['guest' => $guest, 'points' => 30, 'check_in' => '2026-04-10', 'code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], ['hotel_reservations' => ['check_in' => ['from' => '2026-03-01', 'to' => '2026-03-31']]]);

    $row = DB::table('guests')->where('id', $guest)->first();

    expect($row->loyalty_points)->toBe(30)
        ->and($row->lifetime_spend)->toBe(500000);
});

it('resets on-hand stock by removing batches and zeroing quantities', function () {
    $ingredient = $this->f->row('ingredients', $this->tenant->id, ['stock_qty' => 12.5]);
    $batch = $this->f->row('ingredient_batches', $this->tenant->id, ['ingredient_id' => $ingredient]);

    $plan = S::plan($this->tenant, ['inventory_stock_levels']);
    S::purge($this->tenant, $this->admin, ['inventory_stock_levels']);

    expect(collect(app(TenantStateReconciler::class)->preview($plan))->pluck('kind')->all())->toContain('Stock')
        ->and(S::exists('ingredient_batches', $batch))->toBeFalse()
        ->and(S::exists('ingredients', $ingredient))->toBeTrue()
        ->and((float) DB::table('ingredients')->where('id', $ingredient)->value('stock_qty'))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| All or nothing
|--------------------------------------------------------------------------
*/

it('rolls everything back, backup included, when a delete fails part-way', function () {
    S::stay($this->f, $this->tenant->id);
    DB::unprepared("CREATE TRIGGER purge_test_boom BEFORE DELETE ON folios BEGIN SELECT RAISE(ABORT, 'boom'); END");
    $before = S::dump($this->tenant->id);

    expect(fn () => S::purge($this->tenant, $this->admin, ['hotel_reservations']))
        ->toThrow(PurgeConflictException::class, 'rolled back');

    expect(S::dump($this->tenant->id))->toEqual($before)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(TenantDataPurge::query()->count())->toBe(0);
});

it('refuses a purge whose data changed since the preview', function () {
    S::stay($this->f, $this->tenant->id);

    $selections = S::selections(['hotel_reservations']);
    $plan = app(PurgePlanner::class)->plan($this->tenant->id, $selections);

    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);
    $before = S::dump($this->tenant->id);

    expect(fn () => app(DataPurgeService::class)->execute($this->tenant, $selections, $plan->fingerprint, $this->admin, false, null))
        ->toThrow(PurgeConflictException::class, 'changed since you previewed');

    expect(S::dump($this->tenant->id))->toEqual($before)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses to run two operations on one tenant at the same time', function () {
    S::stay($this->f, $this->tenant->id);
    $lock = Cache::lock("tenant-data-operation:{$this->tenant->id}", 60);
    expect($lock->get())->toBeTrue();

    expect(fn () => S::purge($this->tenant, $this->admin, ['hotel_reservations']))
        ->toThrow(PurgeConflictException::class, 'already running');

    expect(S::exists('reservations', DB::table('reservations')->value('id')))->toBeTrue();
});

it('clears the cached menu lists on the database cache store production runs on', function () {
    config(['cache.default' => 'database']);

    Cache::put('menu_items.index.all', ['stale'], 600);
    Cache::put('pos.menu_categories', ['stale'], 600);
    Cache::put('unrelated.key', 'kept', 600);

    PurgeCaches::flush();

    expect(Cache::has('menu_items.index.all'))->toBeFalse()
        ->and(Cache::has('pos.menu_categories'))->toBeFalse()
        ->and(Cache::get('unrelated.key'))->toBe('kept');
});

it('records the purge with a checksum, retention date and audit entry', function () {
    S::stay($this->f, $this->tenant->id);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    expect($purge->backup_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($purge->backup_path))->toBeTrue()
        ->and($purge->backup_sha256)->toBe(hash_file('sha256', Storage::disk('local')->path($purge->backup_path)))
        ->and($purge->expires_at->isSameDay(now()->addDays(90)))->toBeTrue()
        ->and($purge->central_admin_id)->toBe($this->admin->id)
        ->and($purge->summary['numbering'])->toBe('restart')
        ->and(DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'tenant.data_purged')->exists())->toBeTrue();
});
