<?php

use App\Models\Hotel\Ingredient;
use App\Models\Hotel\StockMovement;
use App\Models\Lookup;
use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\StockMovementType;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
});

function grnProduct(string $name = 'Coca-Cola 500ml'): Ingredient
{
    return Ingredient::create([
        'name' => $name, 'unit' => 'pcs', 'stock_qty' => 0, 'low_stock_threshold' => 10,
        'inventory_kind_id' => Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::PRODUCT),
        'selling_price' => 25000, 'active' => true,
    ]);
}

it('creates a draft GRN with a running total and a generated GRN number, without touching stock', function () {
    $manager = staffWithRole('Manager');
    $coke = grnProduct();

    $response = $this->actingAs($manager)->postJson('/api/grns', [
        'reference' => 'INV-1001', 'received_at' => '2026-08-20',
        'lines' => [
            ['ingredient_id' => $coke->id, 'qty' => 50, 'unit_cost' => 18000, 'expiry_date' => '2027-02-01'],
            ['ingredient_id' => $coke->id, 'qty' => 30, 'unit_cost' => 18500, 'expiry_date' => '2027-02-05'],
        ],
    ])->assertCreated();

    expect($response->json('grn.grn_no'))->toStartWith('GRN-')
        ->and($response->json('grn.grn_status_id'))->not->toBeNull()
        ->and($response->json('grn.total_cost'))->toBe(50 * 18000 + 30 * 18500)
        ->and($response->json('grn.lines'))->toHaveCount(2);

    expect($coke->fresh()->stock_qty)->toBe(0.0);
});

it('receives a GRN into per-line batches, updates stock and unit_cost, and logs a grn_receipt movement per line', function () {
    $manager = staffWithRole('Manager');
    $coke = grnProduct();

    $created = $this->actingAs($manager)->postJson('/api/grns', [
        'reference' => 'INV-1001', 'received_at' => '2026-08-20',
        'lines' => [
            ['ingredient_id' => $coke->id, 'qty' => 50, 'unit_cost' => 18000, 'expiry_date' => '2027-02-01'],
            ['ingredient_id' => $coke->id, 'qty' => 30, 'unit_cost' => 18500, 'expiry_date' => '2027-02-05'],
        ],
    ])->assertCreated();
    $grnId = $created->json('grn.id');

    $received = $this->actingAs($manager)->postJson("/api/grns/{$grnId}/receive")->assertOk();

    expect($received->json('grn.status.code'))->toBe('received');
    expect($coke->fresh()->stock_qty)->toBe(80.0)
        ->and($coke->fresh()->unit_cost)->toBe(18500)
        ->and($coke->batches()->count())->toBe(2);

    $movementTypeId = Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, StockMovementType::GRN_RECEIPT);
    expect(StockMovement::query()->where('ingredient_id', $coke->id)->where('movement_type_id', $movementTypeId)->count())->toBe(2);

    // A second receive is refused.
    $this->actingAs($manager)->postJson("/api/grns/{$grnId}/receive")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('grn');
});

it('refuses to edit, delete, or receive-again a received GRN — corrections go through a stock adjustment instead', function () {
    $manager = staffWithRole('Manager');
    $coke = grnProduct();

    $created = $this->actingAs($manager)->postJson('/api/grns', [
        'received_at' => '2026-08-20',
        'lines' => [['ingredient_id' => $coke->id, 'qty' => 10, 'unit_cost' => 18000]],
    ])->assertCreated();
    $grnId = $created->json('grn.id');
    $this->actingAs($manager)->postJson("/api/grns/{$grnId}/receive")->assertOk();

    $this->actingAs($manager)->putJson("/api/grns/{$grnId}", ['reference' => 'changed'])
        ->assertUnprocessable()->assertJsonValidationErrors('grn');
    $this->actingAs($manager)->deleteJson("/api/grns/{$grnId}")
        ->assertUnprocessable()->assertJsonValidationErrors('grn');
    $this->actingAs($manager)->postJson("/api/grns/{$grnId}/cancel")
        ->assertUnprocessable()->assertJsonValidationErrors('grn');
});

it('cancels a draft GRN without touching stock', function () {
    $manager = staffWithRole('Manager');
    $coke = grnProduct();

    $created = $this->actingAs($manager)->postJson('/api/grns', [
        'received_at' => '2026-08-20',
        'lines' => [['ingredient_id' => $coke->id, 'qty' => 10, 'unit_cost' => 18000]],
    ])->assertCreated();

    $this->actingAs($manager)->postJson("/api/grns/{$created->json('grn.id')}/cancel")
        ->assertOk()
        ->assertJsonPath('grn.status.code', 'cancelled');

    expect($coke->fresh()->stock_qty)->toBe(0.0);
});
