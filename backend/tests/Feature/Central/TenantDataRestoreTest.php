<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\DataRestoreService;
use App\Services\Tenancy\DataPurge\PurgeGraph;
use App\Services\Tenancy\DataPurge\PurgePlanner;
use App\Services\Tenancy\DataPurge\PurgeToken;
use App\Services\Tenancy\DataPurge\DataPurgeService;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurgeFixtures;

function doFullPurge(CentralAdmin $admin, Tenant $tenant, array $catKeys, bool $continueNumbering = false): TenantDataPurge
{
    $selections = array_map(fn ($k) => ['key' => $k, 'filters' => []], $catKeys);
    $plan = app(PurgePlanner::class)->plan($tenant->id, $selections);

    $token = PurgeToken::issue(PurgeToken::PURGE, [
        'tenant'      => $tenant->id,
        'admin'       => $admin->id,
        'selections'  => $selections,
        'fingerprint' => $plan->fingerprint,
    ]);

    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    return app(DataPurgeService::class)->execute(
        $tenant,
        $selections,
        $plan->fingerprint,
        $admin,
        $continueNumbering,
        null,
    );
}

beforeEach(function () {
    $this->withoutHeader('X-Tenant-Slug');
    /** @var CentralAdmin $admin */
    $this->admin = CentralAdmin::factory()->create(['password' => bcrypt('secret123')]);
    actingAsCentral($this->admin);
});

// ─── Round-trip restore ───────────────────────────────────────────────────────

it('restores a purged restaurant order round-trip exactly', function () {
    $tenant = Tenant::factory()->create(['slug' => 'restore-rt']);
    $f = new PurgeFixtures;
    $orderId = $f->row('orders', $tenant->id);

    expect(DB::table('orders')->where('id', $orderId)->exists())->toBeTrue();

    $purge = doFullPurge($this->admin, $tenant, ['restaurant_orders']);
    expect(DB::table('orders')->where('id', $orderId)->exists())->toBeFalse();

    // Restore via API
    $previewRes = $this->postJson("/api/central/tenants/{$tenant->id}/data/purges/{$purge->id}/restore-preview");
    $previewRes->assertOk()->assertJsonStructure(['report', 'token', 'min_confirm_seconds']);

    $claims = json_decode(Crypt::decryptString($previewRes->json('token')), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $restoreRes = $this->postJson("/api/central/tenants/{$tenant->id}/data/purges/{$purge->id}/restore", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'secret123',
    ]);

    $restoreRes->assertOk()->assertJsonStructure(['report', 'purge', 'message']);

    // The order is back
    expect(DB::table('orders')->where('id', $orderId)->exists())->toBeTrue();

    // Purge is marked restored
    $purge->refresh();
    expect($purge->status)->toBe('restored');
    expect($purge->restored_at)->not->toBeNull();
});

// ─── Second restore refused ────────────────────────────────────────────────────

it('refuses a second restore', function () {
    $tenant = Tenant::factory()->create(['slug' => 'restore-second']);
    $f = new PurgeFixtures;
    $f->row('orders', $tenant->id);

    $purge = doFullPurge($this->admin, $tenant, ['restaurant_orders']);
    $purge->update(['status' => 'restored', 'restored_at' => now()]);

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purges/{$purge->id}/restore-preview")
        ->assertStatus(422);
});

// ─── Expired purge refused ────────────────────────────────────────────────────

it('refuses restore of an expired purge', function () {
    $tenant = Tenant::factory()->create(['slug' => 'restore-expired']);
    $f = new PurgeFixtures;
    $f->row('orders', $tenant->id);

    $purge = doFullPurge($this->admin, $tenant, ['restaurant_orders']);
    $purge->update(['status' => 'expired', 'backup_path' => null]);

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purges/{$purge->id}/restore-preview")
        ->assertStatus(422);
});

// ─── Checksum mismatch ────────────────────────────────────────────────────────

it('refuses restore when backup checksum does not match', function () {
    $tenant = Tenant::factory()->create(['slug' => 'restore-checksum']);
    $f = new PurgeFixtures;
    $f->row('orders', $tenant->id);

    $purge = doFullPurge($this->admin, $tenant, ['restaurant_orders']);
    $purge->update(['backup_sha256' => str_repeat('d', 64)]); // corrupt the hash

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purges/{$purge->id}/restore-preview")
        ->assertStatus(422);
});

// ─── Conflict (token for wrong purge) ─────────────────────────────────────────

it('rejects a restore token issued for a different purge', function () {
    $tenant = Tenant::factory()->create(['slug' => 'restore-wrongpurge']);
    $f = new PurgeFixtures;
    $f->row('orders', $tenant->id);
    $f->row('orders', $tenant->id);

    $purge1 = doFullPurge($this->admin, $tenant, ['restaurant_orders']);
    // Create a second purge (manual row)
    $purge2 = TenantDataPurge::create([
        'tenant_id'        => $tenant->id,
        'central_admin_id' => $this->admin->id,
        'status'           => 'completed',
        'selections'       => [],
        'summary'          => ['total_rows' => 0, 'warnings' => []],
        'fingerprint'      => str_repeat('e', 64),
        'continue_numbering' => false,
        'total_rows'       => 0,
        'backup_path'      => null,
        'backup_bytes'     => 0,
        'expires_at'       => now()->addDays(90),
    ]);

    // Issue a restore token for purge1 but try to use it against purge2
    $token = PurgeToken::issue(PurgeToken::RESTORE, [
        'tenant' => $tenant->id,
        'admin'  => $this->admin->id,
        'purge'  => $purge1->id,
    ]);
    $claims = json_decode(Crypt::decryptString($token), true);
    $claims['issued_at'] = now()->subSeconds(10)->timestamp;

    $this->postJson("/api/central/tenants/{$tenant->id}/data/purges/{$purge2->id}/restore", [
        'token'    => Crypt::encryptString(json_encode($claims)),
        'password' => 'secret123',
    ])->assertStatus(422);
});

// ─── Coverage guard ───────────────────────────────────────────────────────────

it('check/restore dry-run matches real restore counts', function () {
    $tenant = Tenant::factory()->create(['slug' => 'restore-dryrun']);
    $f = new PurgeFixtures;
    $f->row('orders', $tenant->id);

    $purge = doFullPurge($this->admin, $tenant, ['restaurant_orders']);

    $checkReport  = app(DataRestoreService::class)->check($purge);
    $ordersBefore = DB::table('orders')->where('tenant_id', $tenant->id)->count();

    $restoreReport = app(DataRestoreService::class)->restore($purge->fresh(), $this->admin);

    // The reported totals match
    expect($restoreReport['total_restored'])->toBe($checkReport['total_restored']);
    // Rows actually came back
    expect(DB::table('orders')->where('tenant_id', $tenant->id)->count())->toBeGreaterThan($ordersBefore);
});
