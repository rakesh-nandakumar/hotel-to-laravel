<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\DataRestoreService;
use App\Services\Tenancy\DataPurge\PurgeAbortedException;
use App\Services\Tenancy\DataPurge\PurgeGraph;
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
    $this->restorer = app(DataRestoreService::class);
});

/*
|--------------------------------------------------------------------------
| Round trip
|--------------------------------------------------------------------------
*/

it('restores a fully populated tenant exactly as it was, links and soft-deleted rows included', function () {
    $graph = app(PurgeGraph::class);

    $this->f->populate($this->tenant->id);
    $this->f->populate($this->other->id);

    // A soft-deleted guest and reservation are real rows with real (unique) numbers.
    $ghost = S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0099', 'invoice' => 'INV-2026-0099']);
    DB::table('guests')->where('id', $ghost['guest'])->update(['deleted_at' => now()->toDateTimeString()]);
    DB::table('reservations')->where('id', $ghost['reservation'])->update(['deleted_at' => now()->toDateTimeString()]);

    $before = S::dump($this->tenant->id);
    $otherBefore = S::dump($this->other->id, $graph->tenantTables());

    $purge = S::purge($this->tenant, $this->admin, array_keys(config('tenancy-purge.categories')));
    expect(array_filter(S::counts($this->tenant->id), fn (int $n, string $table): bool => $n > 0 && $graph->isScope($table), ARRAY_FILTER_USE_BOTH))->toBe([]);

    $preview = $this->restorer->check($purge);

    expect($preview['dry_run'])->toBeTrue()
        ->and($preview['skipped']['total'])->toBe(0)
        ->and($preview['renumbered']['total'])->toBe(0)
        ->and(array_sum(array_map('count', S::dump($this->tenant->id))))->toBe(0);   // the dry run changed nothing

    $report = $this->restorer->restore($purge->refresh(), $this->admin);

    expect($report['skipped']['total'])->toBe(0)
        ->and($report['total_restored'])->toBe($purge->total_rows)
        ->and(S::dump($this->tenant->id))->toEqual($before)
        ->and(S::dump($this->other->id, $graph->tenantTables()))->toEqual($otherBefore)
        ->and($purge->refresh()->status)->toBe(TenantDataPurge::STATUS_RESTORED)
        ->and($purge->restored_by)->toBe($this->admin->id);
});

it('puts back links that were only cleared, including the cash ledger source', function () {
    $stay = S::stay($this->f, $this->tenant->id);
    $session = $this->f->row('till_sessions', $this->tenant->id);
    $movement = $this->f->row('till_movements', $this->tenant->id, [
        'till_session_id' => $session,
        'source_type' => 'App\\Models\\Hotel\\Payment',
        'source_id' => $stay['payment'],
    ]);
    $task = $this->f->row('housekeeping_tasks', $this->tenant->id, ['room_id' => $stay['room'], 'reservation_id' => $stay['reservation']]);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    expect(DB::table('housekeeping_tasks')->where('id', $task)->value('reservation_id'))->toBeNull();

    $this->restorer->restore($purge, $this->admin);

    $movementRow = DB::table('till_movements')->where('id', $movement)->first();

    expect($movementRow->source_type)->toBe('App\\Models\\Hotel\\Payment')
        ->and($movementRow->source_id)->toBe($stay['payment'])
        ->and(DB::table('housekeeping_tasks')->where('id', $task)->value('reservation_id'))->toBe($stay['reservation'])
        ->and(S::stayAlive($stay))->toBe(S::stayState(true));
});

it('reverses the status and points repairs a purge made', function () {
    $occupied = $this->f->lookup('room_status', 'occupied');
    $this->f->lookup('room_status', 'available');
    $checkedIn = $this->f->lookup('reservation_status', 'checked_in');

    $room = $this->f->row('rooms', $this->tenant->id, ['room_status_id' => $occupied]);
    $guest = $this->f->row('guests', $this->tenant->id, ['loyalty_points' => 100, 'lifetime_spend' => 500000]);
    S::stay($this->f, $this->tenant->id, ['guest' => $guest, 'room' => $room, 'status' => $checkedIn, 'points' => 40]);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    expect(DB::table('rooms')->where('id', $room)->value('room_status_id'))->not->toBe($occupied)
        ->and(DB::table('guests')->where('id', $guest)->value('loyalty_points'))->toBe(60);

    $this->restorer->restore($purge, $this->admin);

    $guestRow = DB::table('guests')->where('id', $guest)->first();

    expect(DB::table('rooms')->where('id', $room)->value('room_status_id'))->toBe($occupied)
        ->and($guestRow->loyalty_points)->toBe(100)
        ->and($guestRow->lifetime_spend)->toBe(500000);
});

it('keeps what the tenant did since the purge when it reverses a numeric repair', function () {
    $guest = $this->f->row('guests', $this->tenant->id, ['loyalty_points' => 100]);
    S::stay($this->f, $this->tenant->id, ['guest' => $guest, 'points' => 40]);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    DB::table('guests')->where('id', $guest)->update(['loyalty_points' => 75]);   // earned 15 more since

    $this->restorer->restore($purge, $this->admin);

    expect(DB::table('guests')->where('id', $guest)->value('loyalty_points'))->toBe(115);
});

/*
|--------------------------------------------------------------------------
| The tenant carried on working
|--------------------------------------------------------------------------
*/

it('renumbers a restored document whose number was reused since, and reports it', function () {
    $old = S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0001', 'invoice' => 'INV-2026-0001']);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    // Numbering restarted, so the tenant legitimately issued the same numbers again.
    $new = S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0001', 'invoice' => 'INV-2026-0001']);

    $report = $this->restorer->restore($purge, $this->admin);

    expect($report['skipped']['total'])->toBe(0)
        ->and($report['renumbered']['total'])->toBe(2)
        ->and(DB::table('reservations')->where('id', $old['reservation'])->value('code'))->toBe('RSV-0002')
        ->and(DB::table('folios')->where('id', $old['folio'])->value('invoice_no'))->toBe('INV-2026-0002')
        ->and(DB::table('reservations')->where('id', $new['reservation'])->value('code'))->toBe('RSV-0001')
        ->and(collect($report['renumbered']['samples'])->pluck('to')->sort()->values()->all())->toBe(['INV-2026-0002', 'RSV-0002']);
});

it('clears an optional link to something deleted since instead of failing', function () {
    $package = $this->f->row('packages', $this->tenant->id);
    $stay = S::stay($this->f, $this->tenant->id);
    DB::table('reservations')->where('id', $stay['reservation'])->update(['package_id' => $package]);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);
    DB::table('packages')->where('id', $package)->delete();

    $report = $this->restorer->restore($purge, $this->admin);

    expect($report['skipped']['total'])->toBe(0)
        ->and($report['unlinked']['reservations.package_id'])->toBe(1)
        ->and(DB::table('reservations')->where('id', $stay['reservation'])->value('package_id'))->toBeNull();
});

it('leaves a whole document out when part of it cannot be restored', function () {
    $category = $this->f->row('pos_menu_categories', $this->tenant->id);
    $itemA = $this->f->row('pos_menu_items', $this->tenant->id, ['menu_category_id' => $category]);
    $itemB = $this->f->row('pos_menu_items', $this->tenant->id, ['menu_category_id' => $category]);

    $order = $this->f->row('orders', $this->tenant->id);
    $lineA = $this->f->row('order_items', $this->tenant->id, ['order_id' => $order, 'menu_item_id' => $itemA]);
    $lineB = $this->f->row('order_items', $this->tenant->id, ['order_id' => $order, 'menu_item_id' => $itemB]);
    $payment = $this->f->row('payments', $this->tenant->id, ['order_id' => $order]);

    $healthy = $this->f->row('orders', $this->tenant->id);
    $healthyLine = $this->f->row('order_items', $this->tenant->id, ['order_id' => $healthy, 'menu_item_id' => $itemB]);

    $purge = S::purge($this->tenant, $this->admin, ['restaurant_orders']);
    DB::table('pos_menu_items')->where('id', $itemA)->delete();   // the menu changed since

    $report = $this->restorer->restore($purge, $this->admin);

    // Order 1 would have come back without one of its items — a wrong bill. It must not come back at all.
    expect([S::exists('orders', $order), S::exists('order_items', $lineA), S::exists('order_items', $lineB), S::exists('payments', $payment)])
        ->toBe([false, false, false, false])
        ->and([S::exists('orders', $healthy), S::exists('order_items', $healthyLine)])->toBe([true, true])
        ->and($report['skipped']['total'])->toBeGreaterThanOrEqual(4)
        ->and(collect($report['skipped']['samples'])->pluck('reason')->implode(' '))->toContain('whole record was left out');
});

it('never attaches restored rows to a newer record that took over an id', function () {
    $stay = S::stay($this->f, $this->tenant->id);

    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    // Some other record now holds the purged reservation's id (an id reused after a database restart).
    $impostor = $this->f->row('reservations', $this->tenant->id, ['code' => 'RSV-0777']);
    DB::table('reservations')->where('id', $impostor)->update(['id' => $stay['reservation']]);

    $report = $this->restorer->restore($purge, $this->admin);

    expect($report['skipped']['by_table']['reservations'])->toBe(1)
        ->and(DB::table('reservations')->where('id', $stay['reservation'])->value('code'))->toBe('RSV-0777')
        ->and(DB::table('folios')->where('reservation_id', $stay['reservation'])->exists())->toBeFalse()
        ->and(DB::table('reservation_rooms')->where('reservation_id', $stay['reservation'])->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses a backup that fails its integrity check', function () {
    S::stay($this->f, $this->tenant->id);
    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    Storage::disk('local')->put($purge->backup_path, 'tampered');

    expect(fn () => $this->restorer->check($purge))->toThrow(PurgeAbortedException::class, 'integrity check');
    expect(S::counts($this->tenant->id)['reservations'])->toBe(0);
});

it('restores a purge only once', function () {
    S::stay($this->f, $this->tenant->id);
    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    $this->restorer->restore($purge, $this->admin);

    expect(fn () => $this->restorer->restore($purge->refresh(), $this->admin))->toThrow(PurgeAbortedException::class, 'already restored');
});

it('refuses an expired backup', function () {
    S::stay($this->f, $this->tenant->id);
    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);
    $purge->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $this->restorer->check($purge->refresh()))->toThrow(PurgeAbortedException::class, 'expired');
});

it('sweeps up backup files that no purge points to, but not recent ones or referenced ones', function () {
    S::stay($this->f, $this->tenant->id);
    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);   // a real, referenced backup

    $disk = Storage::disk('local');
    $disk->put('tenant-data-backups/9/abandoned.ndjson.gz', 'half written');
    $disk->put('tenant-data-backups/9/running.ndjson.gz', 'being written right now');
    touch($disk->path('tenant-data-backups/9/abandoned.ndjson.gz'), now()->subDays(3)->getTimestamp());
    touch($disk->path($purge->backup_path), now()->subDays(30)->getTimestamp());   // old, but referenced

    $this->artisan('tenant-data:prune-backups')->expectsOutputToContain('Removed 1 orphaned backup file(s).')->assertSuccessful();

    expect($disk->exists('tenant-data-backups/9/abandoned.ndjson.gz'))->toBeFalse()
        ->and($disk->exists('tenant-data-backups/9/running.ndjson.gz'))->toBeTrue()
        ->and($disk->exists($purge->backup_path))->toBeTrue();
});

it('removes expired backups and marks their purge expired, but keeps a restored purge’s status', function () {
    S::stay($this->f, $this->tenant->id);
    $unused = S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);
    $used = S::purge($this->tenant, $this->admin, ['hotel_reservations']);
    $this->restorer->restore($used, $this->admin);

    $fresh = TenantDataPurge::factory()->create(['tenant_id' => $this->tenant->id, 'backup_path' => 'tenant-data-backups/x/keep.ndjson.gz']);
    Storage::disk('local')->put($fresh->backup_path, 'x');

    $this->travel(91)->days();
    $fresh->update(['expires_at' => now()->addDay()]);

    $this->artisan('tenant-data:prune-backups')->expectsOutputToContain('Pruned 2 expired backup(s).')->assertSuccessful();

    expect($unused->refresh()->status)->toBe(TenantDataPurge::STATUS_EXPIRED)
        ->and($unused->backup_path)->toBeNull()
        ->and($used->refresh()->status)->toBe(TenantDataPurge::STATUS_RESTORED)
        ->and($used->backup_path)->toBeNull()
        ->and(Storage::disk('local')->exists($fresh->backup_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('tenant-data-backups/'.$this->tenant->id))->toBe([]);
});
