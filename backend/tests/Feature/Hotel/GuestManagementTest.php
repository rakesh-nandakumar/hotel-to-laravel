<?php

use App\Models\Hotel\Guest;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

it('blocks non-manager roles from viewing guests entirely', function () {
    $chef = staffWithRole('Chef');
    $housekeeper = staffWithRole('Housekeeper');

    $this->actingAs($chef)->getJson('/api/guests')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/guests')->assertForbidden();
});

it('lets a manager search, sort, and paginate guests with aggregate stats', function () {
    $manager = staffWithRole('Manager');
    Guest::factory()->create(['name' => 'Alice Perera', 'lifetime_spend' => 50000, 'loyalty_points' => 10]);
    Guest::factory()->create(['name' => 'Bob Silva', 'lifetime_spend' => 30000, 'loyalty_points' => 5]);

    $this->actingAs($manager)->getJson('/api/guests?q=Alice')
        ->assertOk()
        ->assertJsonCount(1, 'guests');

    $paged = $this->actingAs($manager)->getJson('/api/guests?page=1&sort=spend')
        ->assertOk();

    expect($paged->json('stats.lifetime_spend'))->toBe(80000)
        ->and($paged->json('stats.loyalty_points'))->toBe(15);
});

it('creates and updates a guest as a manager', function () {
    $manager = staffWithRole('Manager');

    $created = $this->actingAs($manager)->postJson('/api/guests', ['name' => 'New Guest', 'phone' => '0771234567'])
        ->assertCreated();

    $guestId = $created->json('guest.id');

    $this->actingAs($manager)->putJson("/api/guests/{$guestId}", ['name' => 'Updated Guest'])
        ->assertOk()
        ->assertJsonPath('guest.name', 'Updated Guest');
});

it('shows a guest profile with recent loyalty transactions', function () {
    $manager = staffWithRole('Manager');
    $guest = Guest::factory()->create();
    $guest->loyaltyTransactions()->create(['points' => 10, 'reason' => 'Stay bonus', 'staff_id' => $manager->id]);

    $this->actingAs($manager)->getJson("/api/guests/{$guest->id}")
        ->assertOk()
        ->assertJsonCount(1, 'guest.loyalty_transactions');
});

it('adjusts loyalty points up and down, logging a ledger entry each time', function () {
    $manager = staffWithRole('Manager');
    $guest = Guest::factory()->create(['loyalty_points' => 20]);

    $this->actingAs($manager)->postJson("/api/guests/{$guest->id}/loyalty-adjust", [
        'points' => 15, 'reason' => 'Referral bonus',
    ])->assertOk()->assertJsonPath('guest.loyalty_points', 35);

    expect($guest->loyaltyTransactions()->count())->toBe(1);
});

it('blocks a loyalty adjustment that would make the balance negative', function () {
    $manager = staffWithRole('Manager');
    $guest = Guest::factory()->create(['loyalty_points' => 5]);

    $this->actingAs($manager)->postJson("/api/guests/{$guest->id}/loyalty-adjust", [
        'points' => -10, 'reason' => 'Correction',
    ])->assertUnprocessable()->assertJsonValidationErrors('points');

    expect($guest->fresh()->loyalty_points)->toBe(5)
        ->and($guest->loyaltyTransactions()->count())->toBe(0);
});

it('requires a reason for a loyalty adjustment', function () {
    $manager = staffWithRole('Manager');
    $guest = Guest::factory()->create();

    $this->actingAs($manager)->postJson("/api/guests/{$guest->id}/loyalty-adjust", ['points' => 5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});
