<?php

use App\Http\Requests\Hotel\StoreMenuItemRequest;
use App\Models\CentralAdmin;
use App\Models\Hotel\Reservation;
use App\Models\Tenant;
use App\Services\CurrentContext;
use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\Support\PurgeFixtures;
use Tests\Support\PurgeScenarios as S;

beforeEach(function () {
    Storage::fake('local');
    $this->withoutHeader('X-Tenant-Slug');
    $this->admin = CentralAdmin::factory()->create();
    actingAsCentral($this->admin);

    $this->f = new PurgeFixtures;
    $this->tenant = Tenant::factory()->create();
});

function nextReservationCode(Tenant $tenant): string
{
    return app(CurrentContext::class)->runForTenant(
        $tenant->id,
        fn (): string => app(DocumentNumberService::class)->next(Reservation::class, 'code', 'RSV-'),
    );
}

function floorOf(Tenant $tenant, string $prefix): ?int
{
    $value = DB::table('tenant_document_number_floors')->where('tenant_id', $tenant->id)->where('prefix', $prefix)->value('last_number');

    return $value === null ? null : (int) $value;
}

/*
|--------------------------------------------------------------------------
| Document numbers across a purge
|--------------------------------------------------------------------------
*/

it('restarts numbering from whatever is left after a purge', function () {
    foreach ([1, 2, 3] as $n) {
        S::stay($this->f, $this->tenant->id, ['code' => sprintf('RSV-%04d', $n), 'invoice' => sprintf('INV-2026-%04d', $n)]);
    }

    S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    expect(nextReservationCode($this->tenant))->toBe('RSV-0001')
        ->and(DB::table('tenant_document_number_floors')->count())->toBe(0);
});

it('carries on after the survivors when only some documents are purged', function () {
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0001', 'invoice' => 'INV-2026-0001', 'check_in' => '2026-03-10']);
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002', 'check_in' => '2026-04-10']);
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0003', 'invoice' => 'INV-2026-0003', 'check_in' => '2026-05-10']);

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], ['hotel_reservations' => ['check_in' => ['from' => '2026-04-01']]]);

    // RSV-0001 survives; the purged 0002 and 0003 are free again, so numbering resumes right after the survivor.
    expect(nextReservationCode($this->tenant))->toBe('RSV-0002');
});

it('never reissues a deleted number when numbering continues', function () {
    foreach ([1, 2, 3] as $n) {
        S::stay($this->f, $this->tenant->id, ['code' => sprintf('RSV-%04d', $n), 'invoice' => sprintf('INV-2026-%04d', $n)]);
    }

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], continueNumbering: true);

    expect(floorOf($this->tenant, 'RSV-'))->toBe(3)
        ->and(floorOf($this->tenant, 'INV-2026-'))->toBe(3)
        ->and(nextReservationCode($this->tenant))->toBe('RSV-0004');
});

it('lets a later restart purge clear the floor an earlier continue purge left', function () {
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0001', 'invoice' => 'INV-2026-0001']);
    S::purge($this->tenant, $this->admin, ['hotel_reservations'], continueNumbering: true);
    expect(floorOf($this->tenant, 'RSV-'))->toBe(1);

    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);
    S::purge($this->tenant, $this->admin, ['hotel_reservations']);

    expect(floorOf($this->tenant, 'RSV-'))->toBeNull()
        ->and(nextReservationCode($this->tenant))->toBe('RSV-0001');
});

it('keeps numbering floors apart per tenant and per series', function () {
    $other = Tenant::factory()->create();
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0007', 'invoice' => 'INV-2026-0007']);

    S::purge($this->tenant, $this->admin, ['hotel_reservations'], continueNumbering: true);

    expect(nextReservationCode($this->tenant))->toBe('RSV-0008')
        ->and(nextReservationCode($other))->toBe('RSV-0001')
        ->and(floorOf($other, 'RSV-'))->toBeNull();
});

it('counts soft-deleted documents so a new number cannot hit their unique index', function () {
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0001', 'invoice' => 'INV-2026-0001']);
    $archived = S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0002', 'invoice' => 'INV-2026-0002']);
    DB::table('reservations')->where('id', $archived['reservation'])->update(['deleted_at' => now()->toDateTimeString()]);

    $next = nextReservationCode($this->tenant);

    expect($next)->toBe('RSV-0003');

    // And it really can be stored — the unique (tenant_id, code) index still holds the archived RSV-0002.
    expect(fn () => $this->f->row('reservations', $this->tenant->id, ['code' => $next]))->not->toThrow(Throwable::class);
});

it('keeps working for the tenant after a purge: new documents number and save without errors', function () {
    S::stay($this->f, $this->tenant->id, ['code' => 'RSV-0001', 'invoice' => 'INV-2026-0001']);
    S::purge($this->tenant, $this->admin, ['hotel_reservations', 'hotel_guests', 'restaurant_orders']);

    $codes = [];

    foreach (range(1, 3) as $ignored) {
        $codes[] = $code = nextReservationCode($this->tenant);
        $this->f->row('reservations', $this->tenant->id, ['code' => $code]);
    }

    expect($codes)->toBe(['RSV-0001', 'RSV-0002', 'RSV-0003']);
});

it('splits a document code into series, number and width', function (string $code, ?array $expected) {
    expect(DocumentNumberService::parse($code))->toBe($expected);
})->with([
    'invoice' => ['INV-2026-0012', ['prefix' => 'INV-2026-', 'number' => 12, 'width' => 4]],
    'booking' => ['RSV-7', ['prefix' => 'RSV-', 'number' => 7, 'width' => 1]],
    'long' => ['APT-INV-2026-00100', ['prefix' => 'APT-INV-2026-', 'number' => 100, 'width' => 5]],
    'no dash' => ['RSV0007', null],
    'no number' => ['RSV-', null],
    'text tail' => ['RSV-12A', null],
]);

/*
|--------------------------------------------------------------------------
| Menu item numbers belong to one tenant
|--------------------------------------------------------------------------
*/

it('lets two tenants use the same menu item number', function () {
    $other = Tenant::factory()->create();
    $this->f->row('pos_menu_items', $this->tenant->id, ['item_no' => 1]);

    $accepts = fn (Tenant $tenant): bool => app(CurrentContext::class)->runForTenant(
        $tenant->id,
        fn (): bool => Validator::make(['item_no' => 1], ['item_no' => (new StoreMenuItemRequest)->rules()['item_no']])->passes(),
    );

    expect($accepts($other))->toBeTrue()
        ->and($accepts($this->tenant))->toBeFalse();
});
