<?php

use App\Models\Hotel\Ingredient;
use App\Models\Lookup;
use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\LookupType;
use Database\Seeders\BranchSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(BranchSeeder::class);
});

/** A product received via two GRN batches — Batch A (50 @ 180, expires first), Batch B (30 @ 185). */
function receiveCokeInTwoBatches($manager): Ingredient
{
    $coke = Ingredient::create([
        'name' => 'Coca-Cola 500ml', 'unit' => 'pcs', 'stock_qty' => 0, 'low_stock_threshold' => 10,
        'inventory_kind_id' => Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::PRODUCT),
        'selling_price' => 25000, 'active' => true,
    ]);

    $grnId = $manager->postJson('/api/grns', [
        'received_at' => '2026-08-20',
        'lines' => [
            ['ingredient_id' => $coke->id, 'qty' => 50, 'unit_cost' => 18000, 'expiry_date' => '2027-02-01'],
            ['ingredient_id' => $coke->id, 'qty' => 30, 'unit_cost' => 18500, 'expiry_date' => '2027-02-05'],
        ],
    ])->json('grn.id');
    $manager->postJson("/api/grns/{$grnId}/receive")->assertOk();

    return $coke->fresh();
}

it('sells a product, draining Batch A first and leaving Batch B untouched, with no KOT line', function () {
    $staff = staffWithRole('Manager');
    $manager = $this->actingAs($staff);
    $coke = receiveCokeInTwoBatches($manager);
    [$batchA, $batchB] = $coke->batches()->orderBy('expiry_date')->get()->all();

    $created = $manager->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['product_id' => $coke->id, 'qty' => 20]],
    ])->assertCreated();

    expect($coke->fresh()->stock_qty)->toBe(60.0)
        ->and($batchA->fresh()->qty)->toBe(30.0)
        ->and($batchB->fresh()->qty)->toBe(30.0);

    $kot = $manager->getJson('/api/orders/kot')->assertOk();
    $ticket = collect($kot->json('orders'))->firstWhere('id', $created->json('order.id'));
    expect($ticket)->toBeNull();
});

it('restocks the exact batches (and costs) a voided product line drew from', function () {
    $staff = staffWithRole('Manager');
    $manager = $this->actingAs($staff);
    $coke = receiveCokeInTwoBatches($manager);
    [$batchA, $batchB] = $coke->batches()->orderBy('expiry_date')->get()->all();

    $created = $manager->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['product_id' => $coke->id, 'qty' => 20]],
    ])->assertCreated();
    $itemId = collect($created->json('order.items'))->firstWhere('product_id', $coke->id)['id'];

    expect($batchA->fresh()->qty)->toBe(30.0);

    $manager->postJson("/api/orders/{$created->json('order.id')}/items/{$itemId}/void", ['reason' => 'Customer changed mind'])
        ->assertOk();

    expect($coke->fresh()->stock_qty)->toBe(80.0)
        ->and($batchA->fresh()->qty)->toBe(50.0)
        ->and($batchB->fresh()->qty)->toBe(30.0);
});

it('rejects a product sale that exceeds available stock', function () {
    $staff = staffWithRole('Manager');
    $manager = $this->actingAs($staff);
    $coke = receiveCokeInTwoBatches($manager);

    $manager->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['product_id' => $coke->id, 'qty' => 999]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('rejects an out-of-stock product line before it ever reaches inventory deduction', function () {
    $staff = staffWithRole('Manager');
    $manager = $this->actingAs($staff);
    $empty = Ingredient::create([
        'name' => 'Sprite 500ml', 'unit' => 'pcs', 'stock_qty' => 0, 'low_stock_threshold' => 10,
        'inventory_kind_id' => Lookup::id(LookupType::INVENTORY_KIND, InventoryKind::PRODUCT),
        'selling_price' => 25000, 'active' => true,
    ]);

    $manager->postJson('/api/orders', [
        'type' => 'walkin', 'items' => [['product_id' => $empty->id, 'qty' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items');
});
