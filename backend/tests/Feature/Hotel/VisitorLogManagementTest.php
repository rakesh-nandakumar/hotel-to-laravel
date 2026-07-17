<?php

use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
});

it('blocks chef and housekeeper from the visitor log', function () {
    $chef = staffWithRole('Chef');
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($chef)->getJson('/api/visitors')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/visitors')->assertForbidden();
});

it('lets security sign a visitor in and out', function () {
    $security = staffWithRole('Security');

    $created = $this->actingAs($security)->postJson('/api/visitors', [
        'name' => 'John Delivery', 'vehicle_no' => 'ABC-1234', 'purpose' => 'Supplier delivery',
    ])->assertCreated();

    expect($created->json('visitor.time_out'))->toBeNull();

    $this->actingAs($security)->postJson("/api/visitors/{$created->json('visitor.id')}/out")
        ->assertOk()
        ->assertJsonPath('visitor.time_out', fn ($v) => $v !== null);
});

it('rejects signing out a visitor twice', function () {
    $security = staffWithRole('Security');
    $created = $this->actingAs($security)->postJson('/api/visitors', ['name' => 'Jane Guest'])->json('visitor');

    $this->actingAs($security)->postJson("/api/visitors/{$created['id']}/out")->assertOk();

    $this->actingAs($security)->postJson("/api/visitors/{$created['id']}/out")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('visitor');
});

it('lets a manager list the visitor log', function () {
    $manager = staffWithRole('Manager');
    $security = staffWithRole('Security');
    $this->actingAs($security)->postJson('/api/visitors', ['name' => 'Jane Guest'])->assertCreated();

    $this->actingAs($manager)->getJson('/api/visitors')->assertOk()->assertJsonCount(1, 'visitors');
});
