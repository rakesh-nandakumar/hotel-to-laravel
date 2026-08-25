<?php

use App\Models\Hotel\Ingredient;
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

function fefoIngredient(int $stock): Ingredient
{
    return Ingredient::create(['name' => 'Chicken', 'unit' => 'g', 'stock_qty' => $stock, 'low_stock_threshold' => 100]);
}

it('drains the earliest-expiring batch first among several dated batches', function () {
    $chef = staffWithRole('Chef');
    $ingredient = fefoIngredient(300);
    $aug25 = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-08-25', 'received_at' => now()->subDays(3)]);
    $aug30 = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-08-30', 'received_at' => now()->subDays(2)]);
    $sep15 = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-09-15', 'received_at' => now()->subDay()]);

    $this->actingAs($chef)->postJson("/api/ingredients/{$ingredient->id}/adjust", [
        'delta' => -10, 'reason' => 'test',
    ])->assertOk();

    expect($aug25->fresh()->qty)->toBe(90.0)
        ->and($aug30->fresh()->qty)->toBe(100.0)
        ->and($sep15->fresh()->qty)->toBe(100.0);
});

it('tie-breaks batches sharing the same expiry by the older received_at', function () {
    $chef = staffWithRole('Chef');
    $ingredient = fefoIngredient(200);
    $older = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-09-01', 'received_at' => now()->subDays(5)]);
    $newer = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-09-01', 'received_at' => now()->subDay()]);

    $this->actingAs($chef)->postJson("/api/ingredients/{$ingredient->id}/adjust", [
        'delta' => -10, 'reason' => 'test',
    ])->assertOk();

    expect($older->fresh()->qty)->toBe(90.0)
        ->and($newer->fresh()->qty)->toBe(100.0);
});

it('drains undated batches purely FIFO by received_at when nothing has an expiry', function () {
    $chef = staffWithRole('Chef');
    $ingredient = fefoIngredient(200);
    $older = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'received_at' => now()->subDays(5)]);
    $newer = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'received_at' => now()->subDay()]);

    $this->actingAs($chef)->postJson("/api/ingredients/{$ingredient->id}/adjust", [
        'delta' => -10, 'reason' => 'test',
    ])->assertOk();

    expect($older->fresh()->qty)->toBe(90.0)
        ->and($newer->fresh()->qty)->toBe(100.0);
});

it('drains every dated batch before any undated batch, regardless of received_at', function () {
    // The bug this guards against: MySQL sorts NULL expiry_date FIRST on a
    // plain ASC order, so a naive orderBy('expiry_date') drains undated stock
    // before expiring stock — exactly backwards from FEFO intent.
    $chef = staffWithRole('Chef');
    $ingredient = fefoIngredient(200);
    $undated = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'received_at' => now()->subDays(10)]);
    $dated = $ingredient->batches()->create(['qty' => 100, 'initial_qty' => 100, 'expiry_date' => '2026-09-01', 'received_at' => now()->subDay()]);

    $this->actingAs($chef)->postJson("/api/ingredients/{$ingredient->id}/adjust", [
        'delta' => -10, 'reason' => 'test',
    ])->assertOk();

    expect($dated->fresh()->qty)->toBe(90.0)
        ->and($undated->fresh()->qty)->toBe(100.0);
});
