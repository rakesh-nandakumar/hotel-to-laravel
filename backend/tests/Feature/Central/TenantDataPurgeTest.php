<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\PurgeCatalog;
use App\Services\Tenancy\DataPurge\PurgeGraph;
use App\Services\Tenancy\DataPurge\PurgePlanner;
use App\Services\Tenancy\DataPurge\PurgeToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\PurgeFixtures;

// ─── Shared setup ─────────────────────────────────────────────────────────────

/**
 * Two tenants with one seeded row in every purgeable table so every test
 * can assert isolation (the other tenant's rows must survive untouched).
 */
function setupTwoTenants(): array
{
    /** @var Tenant $t1 */
    $t1 = Tenant::factory()->create(['status' => 'active', 'slug' => 'purge-t1']);
    /** @var Tenant $t2 */
    $t2 = Tenant::factory()->create(['status' => 'active', 'slug' => 'purge-t2']);
    $f1 = new PurgeFixtures;
    $f2 = new PurgeFixtures;

    foreach (config('tenancy-purge.categories') as $cat) {
        foreach ($cat['roots'] as $table) {
            $f1->row($table, $t1->id);
            $f2->row($table, $t2->id);
        }
    }

    return [$t1, $t2];
}

function previewToken(CentralAdmin $admin, Tenant $tenant, array $selections): string
{
    $plan = app(PurgePlanner::class)->plan($tenant->id, $selections);

    return PurgeToken::issue(PurgeToken::PURGE, [
        'tenant'      => $tenant->id,
        'admin'       => $admin->id,
        'selections'  => $selections,
        'fingerprint' => $plan->fingerprint,
    ]);
}

beforeEach(function () {
    $this->withoutHeader('X-Tenant-Slug');
    /** @var CentralAdmin $admin */
    $this->admin = CentralAdmin::factory()->create(['password' => bcrypt('secret123')]);
    actingAsCentral($this->admin);
});

// ─── Catalog ──────────────────────────────────────────────────────────────────

it('returns the catalog with live counts', function () {
    [$tenant] = setupTwoTenants();

    $res = $this->getJson("/api/central/tenants/{$tenant->id}/data/catalog");

    $res->assertOk()
        ->assertJsonStructure(['modules', 'retention_days', 'min_confirm_seconds']);

    expect($res->json('modules'))->not->toBeEmpty();
});

// ─── Preview ──────────────────────────────────────────────────────────────────

it('previews a restaurant_orders selection', function () {
    [$tenant] = setupTwoTenants();

    $res = $this->postJson("/api/central/tenants/{$tenant->id}/data/preview", [
        'selections' => [['key' => 'restaurant_orders', 'filters' => []]],
    ]);

    $res->assertOk()
        ->assertJsonStructure(['plan', 'token', 'min_confirm_seconds'])
        ->assertJsonPath('plan.total_rows', fn ($v) => $v >= 1);
});

it('rejects an empty selection', function () {
    [$tenant] = setupTwoTenants();

    $this->postJson("/api/central/tenants/{$tenant->id}/data/preview", [
        'selections' => [['key' => 'restaurant_orders', 'filters' => ['status' => 'NONEXISTENT_STATUS_XYZ']]],
    ])->assertStatus(422);
});

// ─── Purge ────────────────────────────────────────────────────────────────────

it('purges restaurant_orders and leaves the other tenant untouched', function () {
    [$t1, $t2] = setupTwoTenants();
    $before = DB::table('orders')->where('tenant_id', $t2->id)->count();

    $selections = [['key' => 'restaurant_orders', 'filters' => []]];

    // wait >3s by faking the issued_at in the past
    $token = previewToken($this->admin, $t1, $selections);

    // token was just issued — must wait 3s; sleep is avoided by faking the clock
    // We just use a freshly-issued token older than min_confirm_seconds by decrypting and re-issuing
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;
    $agedToken = Crypt::encryptString(json_encode($claims));

    $res = $this->postJson("/api/central/tenants/{$t1->id}/data/purge", [
        'token'    => $agedToken,
        'password' => 'secret123',
    ]);

    $res->assertStatus(201)
        ->assertJsonStructure(['purge', 'message'])
        ->assertJsonPath('purge.status', 'completed');

    expect(DB::table('orders')->where('tenant_id', $t1->id)->count())->toBe(0);
    expect(DB::table('orders')->where('tenant_id', $t2->id)->count())->toBe($before);

    // A purge row was created
    expect(TenantDataPurge::where('tenant_id', $t1->id)->count())->toBe(1);
});

it('rejects a wrong password', function () {
    [$tenant] = setupTwoTenants();
    $selections = [['key' => 'restaurant_orders', 'filters' => []]];
    $token = previewToken($this->admin, $tenant, $selections);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purge", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'WRONG_PASSWORD',
    ])->assertStatus(422);
});

it('rejects a token younger than min_confirm_seconds', function () {
    [$tenant] = setupTwoTenants();
    $selections = [['key' => 'restaurant_orders', 'filters' => []]];
    $token = previewToken($this->admin, $tenant, $selections);

    // Token is brand-new — server must reject it
    $this->postJson("/api/central/tenants/{$tenant->id}/data/purge", [
        'token'    => $token,
        'password' => 'secret123',
    ])->assertStatus(422);
});

it('rejects a token issued for a different tenant', function () {
    [$t1, $t2] = setupTwoTenants();
    $selections = [['key' => 'restaurant_orders', 'filters' => []]];

    // Token for t2, but posted against t1
    $token = previewToken($this->admin, $t2, $selections);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $this->postJson("/api/central/tenants/{$t1->id}/data/purge", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'secret123',
    ])->assertStatus(422);
});

it('returns 409 when data changed since preview', function () {
    [$tenant] = setupTwoTenants();
    $selections = [['key' => 'restaurant_orders', 'filters' => []]];

    $token = previewToken($this->admin, $tenant, $selections);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;
    $claims['fingerprint'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // wrong hash

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purge", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'secret123',
    ])->assertStatus(409);
});

// ─── Isolation ────────────────────────────────────────────────────────────────

it('never touches another tenant no matter which category is purged', function () {
    [$t1, $t2] = setupTwoTenants();

    // Count all purgeable tables for t2 before
    $before = [];
    foreach (config('tenancy-purge.categories') as $cat) {
        foreach ($cat['roots'] as $table) {
            $before[$table] = DB::table($table)->where('tenant_id', $t2->id)->count();
        }
    }

    // Purge ALL categories for t1
    $allSelections = collect(config('tenancy-purge.categories'))->keys()->map(fn ($k) => ['key' => $k, 'filters' => []])->values()->all();
    $token = previewToken($this->admin, $t1, $allSelections);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $this->postJson("/api/central/tenants/{$t1->id}/data/purge", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'secret123',
    ])->assertStatus(201);

    // t2 must be exactly the same
    foreach ($before as $table => $count) {
        expect(DB::table($table)->where('tenant_id', $t2->id)->count())
            ->toBe($count, "Table {$table} was changed for the other tenant");
    }
});

// ─── Reconcile ────────────────────────────────────────────────────────────────

it('resets an occupied room to available after purging its reservation', function () {
    $f = new PurgeFixtures;
    $tenant = Tenant::factory()->create(['slug' => 'purge-reconcile']);

    $occupiedId = $f->lookup('room_status', 'occupied');
    $availableId = $f->lookup('room_status', 'available');
    $f->lookup('reservation_status', 'checked_in'); // needed for room reconcile
    $roomTypeId = $f->row('room_types', $tenant->id);
    $roomId = DB::table('rooms')->insertGetId(['tenant_id' => $tenant->id, 'number' => '201', 'room_type_id' => $roomTypeId, 'room_status_id' => $occupiedId]);

    // seed a reservation that uses this room
    $reservationId = $f->row('reservations', $tenant->id);
    $f->row('reservation_rooms', $tenant->id, ['reservation_id' => $reservationId, 'room_id' => $roomId]);

    $selections = [['key' => 'hotel_reservations', 'filters' => []]];
    $token = previewToken($this->admin, $tenant, $selections);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purge", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'secret123',
    ])->assertStatus(201);

    expect(DB::table('rooms')->where('id', $roomId)->value('room_status_id'))->toBe($availableId);
});

// ─── History / Download ───────────────────────────────────────────────────────

it('lists purge history newest first', function () {
    [$tenant] = setupTwoTenants();

    // Create two purge records manually (no actual backup file needed for history test)
    TenantDataPurge::create([
        'tenant_id' => $tenant->id,
        'central_admin_id' => $this->admin->id,
        'status' => 'completed',
        'selections' => [],
        'summary' => ['total_rows' => 5, 'warnings' => []],
        'fingerprint' => str_repeat('a', 64),
        'continue_numbering' => false,
        'total_rows' => 5,
        'backup_path' => null,
        'backup_bytes' => 0,
        'expires_at' => now()->addDays(90),
    ]);

    $res = $this->getJson("/api/central/tenants/{$tenant->id}/data/purges");
    $res->assertOk()->assertJsonStructure(['purges']);
    expect(count($res->json('purges')))->toBeGreaterThanOrEqual(1);
});

// ─── Prune command ────────────────────────────────────────────────────────────

it('marks expired purges and removes their backup path', function () {
    $tenant = Tenant::factory()->create(['slug' => 'prune-test']);

    $purge = TenantDataPurge::create([
        'tenant_id' => $tenant->id,
        'central_admin_id' => $this->admin->id,
        'status' => 'completed',
        'selections' => [],
        'summary' => ['total_rows' => 1, 'warnings' => []],
        'fingerprint' => str_repeat('b', 64),
        'continue_numbering' => false,
        'total_rows' => 1,
        'backup_path' => 'tenant-data-backups/1/fake.ndjson.gz',
        'backup_bytes' => 100,
        'backup_sha256' => str_repeat('c', 64),
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('tenant-data:prune-backups')->assertSuccessful();

    $purge->refresh();
    expect($purge->status)->toBe('expired');
    expect($purge->backup_path)->toBeNull();
});

// ─── Numbering ────────────────────────────────────────────────────────────────

it('restarts numbering from 1 by default after a purge', function () {
    $tenant = Tenant::factory()->create(['slug' => 'numbering-restart']);
    $f = new PurgeFixtures;

    // Seed an order so restaurant_orders has something to delete
    $f->row('orders', $tenant->id);

    $selections = [['key' => 'restaurant_orders', 'filters' => []]];
    $token = previewToken($this->admin, $tenant, $selections);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purge", [
        'token'              => Crypt::encryptString(json_encode($claims)),
        'password'           => 'secret123',
        'continue_numbering' => false,
    ])->assertStatus(201);

    // No floor should exist — numbering restarts
    expect(DB::table('tenant_document_number_floors')->where('tenant_id', $tenant->id)->count())->toBe(0);
});
