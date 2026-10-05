<?php

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PurgeFixtures;
use Tests\Support\PurgeScenarios as S;

/*
|--------------------------------------------------------------------------
| The JSON the master-control UI reads
|--------------------------------------------------------------------------
|
| web/src/pages/central/TenantDataTab.tsx is typed against these shapes. A
| rename here compiles fine on both sides and then crashes the page at runtime,
| so the shapes are pinned: change them together with the TypeScript types.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->withoutHeader('X-Tenant-Slug');
    $this->admin = CentralAdmin::factory()->create();   // password: "password"
    actingAsCentral($this->admin);

    $this->f = new PurgeFixtures;
    $this->tenant = Tenant::factory()->create();
    $this->base = "/api/central/tenants/{$this->tenant->id}/data";
});

it('describes every category the way the selection screen draws it', function () {
    $this->f->lookup('reservation_status', 'confirmed');
    S::stay($this->f, $this->tenant->id);

    $response = $this->getJson("{$this->base}/catalog")->assertOk();

    $response->assertJsonStructure([
        'retention_days',
        'min_confirm_seconds',
        'modules' => ['*' => ['key', 'label', 'categories' => ['*' => ['key', 'label', 'description', 'master', 'count', 'roots', 'filters']]]],
    ]);

    $categories = collect($response->json('modules'))->flatMap(fn (array $module): array => $module['categories']);

    expect($response->json('retention_days'))->toBeInt()
        ->and($response->json('min_confirm_seconds'))->toBe(3)
        ->and($categories->firstWhere('key', 'hotel_reservations')['count'])->toBe(1)
        ->and($categories->firstWhere('key', 'hotel_rooms')['master'])->toBeTrue();

    foreach ($categories->pluck('filters')->flatten(1) as $filter) {
        expect($filter)->toHaveKeys(['key', 'label', 'type'])
            ->and($filter['type'])->toBeIn(['date', 'month', 'lookup']);

        if ($filter['type'] === 'lookup') {
            expect($filter['options'])->toBeArray();

            foreach ($filter['options'] as $option) {
                expect($option)->toHaveKeys(['id', 'code', 'name'])->and($option['id'])->toBeInt();
            }
        }
    }

    $status = collect($categories->firstWhere('key', 'hotel_reservations')['filters'])->firstWhere('key', 'status');

    expect($status['options'])->not->toBe([]);
});

it('previews a purge with the sections the confirmation screen shows', function () {
    $stay = S::stay($this->f, $this->tenant->id);
    $order = $this->f->row('orders', $this->tenant->id, ['reservation_id' => $stay['reservation']]);
    $this->f->row('folio_lines', $this->tenant->id, ['folio_id' => $stay['folio'], 'order_id' => $order]);

    $response = $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'hotel_guests', 'filters' => []]]])->assertOk();

    $response->assertJsonStructure([
        'token',
        'min_confirm_seconds',
        'retention_days',
        'plan' => [
            'total_rows', 'warnings', 'zero_stock', 'fingerprint', 'reconcile',
            'categories' => ['*' => ['key', 'label', 'module', 'master', 'count']],
            'tables' => ['*' => ['table', 'label', 'count', 'selected']],
            'also_deleted' => ['*' => ['table', 'label', 'count', 'because' => ['*' => ['kind', 'table', 'label', 'count']]]],
            'unlinked',
        ],
    ]);

    $plan = $response->json('plan');
    $dependents = collect($plan['also_deleted'])->pluck('count', 'table');

    expect($plan['categories'][0]['key'])->toBe('hotel_guests')
        ->and($plan['categories'][0]['count'])->toBe(1)
        ->and($plan['total_rows'])->toBe(array_sum(array_column($plan['tables'], 'count')))
        ->and($dependents['reservations'])->toBe(1)
        ->and($dependents['folios'])->toBe(1)
        ->and($plan['warnings'])->each->toBeString()
        ->and($plan['reconcile'])->toBeArray();
});

it('shows the repairs a purge will make and the links it will clear', function () {
    $occupied = $this->f->lookup('room_status', 'occupied');
    $this->f->lookup('room_status', 'available');
    $room = $this->f->row('rooms', $this->tenant->id, ['room_status_id' => $occupied]);
    $stay = S::stay($this->f, $this->tenant->id, ['room' => $room, 'status' => $this->f->lookup('reservation_status', 'checked_in')]);
    $task = $this->f->row('housekeeping_tasks', $this->tenant->id, ['room_id' => $room, 'reservation_id' => $stay['reservation']]);

    $plan = $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'hotel_reservations']]])->assertOk()->json('plan');

    expect(collect($plan['reconcile'])->pluck('kind')->all())->toContain('Rooms')
        ->and(collect($plan['reconcile'])->first()['description'])->toBeString()
        ->and(collect($plan['unlinked'])->firstWhere('column', 'reservation_id'))->toMatchArray(['table' => 'housekeeping_tasks', 'count' => 1])
        ->and(S::exists('housekeeping_tasks', $task))->toBeTrue();
});

it('accepts the filter shapes the UI sends and rejects any other', function () {
    S::stay($this->f, $this->tenant->id);
    $statusId = $this->f->lookup('reservation_status', 'confirmed');

    $send = fn (array $filters) => $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'hotel_reservations', 'filters' => $filters]]]);

    // A from/to range (either end may be empty), lookup ids, and a month range.
    $send(['check_in' => ['from' => '2026-03-01', 'to' => '2026-03-31']])->assertOk();
    $send(['check_in' => ['from' => '2026-03-01', 'to' => null]])->assertOk();
    $send(['status' => [$statusId]])->assertOk();
    $send(['check_in' => ['from' => '', 'to' => '']])->assertOk();   // an emptied filter means "everything"

    // The old single-string shape is not a filter.
    $send(['check_in' => '2026-03-10'])->assertUnprocessable()->assertJsonValidationErrors('selections.0.filters.check_in');
    $send(['check_in' => ['from' => '10/03/2026']])->assertUnprocessable()->assertJsonPath('errors', fn (array $errors): bool => str_contains(json_encode($errors), 'YYYY-MM-DD'));
    $send(['check_in' => ['from' => '2026-04-01', 'to' => '2026-03-01']])->assertUnprocessable();
    $send(['status' => [999999]])->assertUnprocessable();
    $send(['nonsense' => ['from' => '2026-03-01']])->assertUnprocessable();

    $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'no_such_category']]])->assertUnprocessable();
    $this->postJson("{$this->base}/preview", ['selections' => []])->assertUnprocessable();
});

it('walks the exact flow the UI performs: preview, purge, history, download, restore', function () {
    S::stay($this->f, $this->tenant->id, ['check_in' => '2026-03-10']);
    $kept = S::stay($this->f, $this->tenant->id, ['check_in' => '2026-05-10', 'code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);

    $selections = [['key' => 'hotel_reservations', 'filters' => ['check_in' => ['from' => '2026-03-01', 'to' => '2026-03-31']]]];

    $preview = $this->postJson("{$this->base}/preview", ['selections' => $selections])->assertOk();
    $token = $preview->json('token');

    // Too eager: the server enforces the delay itself, not just the countdown in the dialog.
    $this->postJson("{$this->base}/purge", ['token' => $token, 'password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('token');

    $this->travel(4)->seconds();

    $this->postJson("{$this->base}/purge", ['token' => $token, 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $purged = $this->postJson("{$this->base}/purge", ['token' => $token, 'password' => 'password', 'continue_numbering' => false, 'note' => 'client asked'])
        ->assertCreated()
        ->assertJsonStructure(['message', 'purge' => ['id', 'uuid', 'status', 'created_at', 'total_rows', 'summary', 'operator', 'expires_at', 'restorable']]);

    expect($purged->json('purge.status'))->toBe('completed')
        ->and($purged->json('purge.restorable'))->toBeTrue()
        ->and($purged->json('purge.summary.categories.0.label'))->toBe('Reservations & hotel invoices')
        ->and(DB::table('reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(S::stayAlive($kept))->toBe(S::stayState(true))
        ->and(DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'tenant.data_purged')->exists())->toBeTrue();

    $purgeId = $purged->json('purge.id');

    $history = $this->getJson("{$this->base}/purges")->assertOk();
    $row = $history->json('purges.0');

    expect($row['id'])->toBe($purgeId)
        ->and($row)->toHaveKeys(['id', 'uuid', 'status', 'created_at', 'total_rows', 'summary', 'note', 'continue_numbering', 'operator', 'backup_bytes', 'expires_at', 'restorable', 'restored_at', 'restored_by', 'restore_summary'])
        ->and($row['operator'])->toHaveKeys(['id', 'name', 'email'])
        ->and($row['note'])->toBe('client asked')
        ->and($row['backup_bytes'])->toBeGreaterThan(0);

    $this->get("{$this->base}/purges/{$purgeId}/download")->assertOk()->assertDownload();
    expect(DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'tenant.data_backup_downloaded')->exists())->toBeTrue();

    $restorePreview = $this->postJson("{$this->base}/purges/{$purgeId}/restore-preview")->assertOk();
    $restorePreview->assertJsonStructure([
        'token',
        'min_confirm_seconds',
        'report' => [
            'dry_run', 'total_restored', 'unlinked',
            'renumbered' => ['total', 'samples'],
            'skipped' => ['total', 'by_table', 'samples'],
        ],
    ]);

    expect($restorePreview->json('report.dry_run'))->toBeTrue()
        ->and($restorePreview->json('report.total_restored'))->toBe($purged->json('purge.total_rows'))
        ->and(DB::table('reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(1);   // a preview restores nothing

    $this->travel(4)->seconds();

    $this->postJson("{$this->base}/purges/{$purgeId}/restore", ['token' => $restorePreview->json('token'), 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $restored = $this->postJson("{$this->base}/purges/{$purgeId}/restore", ['token' => $restorePreview->json('token'), 'password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['message', 'report' => ['total_restored'], 'purge' => ['status', 'restorable', 'restored_at', 'restored_by']]);

    expect($restored->json('purge.status'))->toBe('restored')
        ->and($restored->json('purge.restorable'))->toBeFalse()
        ->and(DB::table('reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'tenant.data_restored')->exists())->toBeTrue();

    // The same preview cannot be replayed once the purge is restored.
    $this->postJson("{$this->base}/purges/{$purgeId}/restore", ['token' => $restorePreview->json('token'), 'password' => 'password'])->assertUnprocessable();
});

it('answers 409 when the data changed between preview and purge, and deletes nothing', function () {
    S::stay($this->f, $this->tenant->id);
    $token = $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'hotel_reservations']]])->assertOk()->json('token');

    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);
    $this->travel(4)->seconds();

    $this->postJson("{$this->base}/purge", ['token' => $token, 'password' => 'password'])->assertStatus(409);

    expect(DB::table('reservations')->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(TenantDataPurge::query()->count())->toBe(0);
});

it('does not let previews use up the purge’s own rate limit', function () {
    S::stay($this->f, $this->tenant->id);
    $selections = ['selections' => [['key' => 'hotel_reservations']]];

    // An operator tuning filters previews repeatedly; each endpoint has its own budget.
    foreach (range(1, 6) as $ignored) {
        $token = $this->postJson("{$this->base}/preview", $selections)->assertOk()->json('token');
    }

    $this->travel(4)->seconds();

    $this->postJson("{$this->base}/purge", ['token' => $token, 'password' => 'password'])->assertCreated();
});

it('keeps a confirmation tied to the tenant and operator it was issued for', function () {
    S::stay($this->f, $this->tenant->id);
    $otherTenant = Tenant::factory()->create();
    S::stay($this->f, $otherTenant->id);

    $token = $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'hotel_reservations']]])->assertOk()->json('token');
    $this->travel(4)->seconds();

    // Another tenant's URL
    $this->postJson("/api/central/tenants/{$otherTenant->id}/data/purge", ['token' => $token, 'password' => 'password'])->assertUnprocessable();

    // Another operator
    actingAsCentral(CentralAdmin::factory()->create());
    $this->postJson("{$this->base}/purge", ['token' => $token, 'password' => 'password'])->assertUnprocessable();

    expect(DB::table('reservations')->count())->toBe(2);
});

it('is reachable only by a signed-in platform operator on the central host', function () {
    $purge = TenantDataPurge::factory()->create(['tenant_id' => $this->tenant->id]);

    // A tenant's own host never sees master control.
    $this->withHeader('X-Tenant-Slug', $this->tenant->slug)->getJson("{$this->base}/catalog")->assertNotFound();
    $this->withoutHeader('X-Tenant-Slug');

    Auth::guard('central')->forgetUser();

    $this->getJson("{$this->base}/catalog")->assertUnauthorized();
    $this->postJson("{$this->base}/preview", ['selections' => [['key' => 'hotel_guests']]])->assertUnauthorized();
    $this->postJson("{$this->base}/purge", [])->assertUnauthorized();
    $this->getJson("{$this->base}/purges/{$purge->id}/download")->assertUnauthorized();
});

it('never serves or restores another tenant’s backup', function () {
    S::stay($this->f, $this->tenant->id);
    $purge = S::purge($this->tenant, $this->admin, ['hotel_reservations']);
    $otherTenant = Tenant::factory()->create();

    $this->get("/api/central/tenants/{$otherTenant->id}/data/purges/{$purge->id}/download")->assertNotFound();
    $this->postJson("/api/central/tenants/{$otherTenant->id}/data/purges/{$purge->id}/restore-preview")->assertNotFound();
    $this->postJson("/api/central/tenants/{$otherTenant->id}/data/purges/{$purge->id}/restore", ['token' => 'x', 'password' => 'password'])->assertNotFound();
});
