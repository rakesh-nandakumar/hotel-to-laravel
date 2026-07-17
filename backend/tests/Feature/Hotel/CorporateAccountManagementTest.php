<?php

use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Room;
use Database\Seeders\BranchSeeder;
use Database\Seeders\HotelRoomsSeeder;
use Database\Seeders\LookupSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Database\Seeders\SettingsSeeder;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->seed(LookupSeeder::class);
});

it('blocks non-manager roles from viewing corporate accounts entirely', function () {
    $chef = staffWithRole('Chef');
    $housekeeper = staffWithRole('Housekeeper');
    $security = staffWithRole('Security');

    $this->actingAs($chef)->getJson('/api/corporate')->assertForbidden();
    $this->actingAs($housekeeper)->getJson('/api/corporate')->assertForbidden();
    $this->actingAs($security)->getJson('/api/corporate')->assertForbidden();
});

it('lets a manager list corporate accounts with an outstanding placeholder', function () {
    $manager = staffWithRole('Manager');
    CorporateAccount::factory()->create(['company_name' => 'Acme Corp']);

    $response = $this->actingAs($manager)->getJson('/api/corporate')
        ->assertOk()
        ->assertJsonCount(1, 'corporate_accounts.data');

    expect($response->json('corporate_accounts.data.0.outstanding'))->toBe(0);
});

it('creates and updates a corporate account as a manager', function () {
    $manager = staffWithRole('Manager');

    $created = $this->actingAs($manager)->postJson('/api/corporate', [
        'company_name' => 'New Corp',
        'discount_pct' => 10,
        'credit_limit' => 500000,
    ])->assertCreated();

    $accountId = $created->json('corporate_account.id');

    $this->actingAs($manager)->putJson("/api/corporate/{$accountId}", ['company_name' => 'Updated Corp'])
        ->assertOk()
        ->assertJsonPath('corporate_account.company_name', 'Updated Corp');
});

it('lets an owner create corporate accounts too', function () {
    $owner = staffWithRole('Owner');

    $this->actingAs($owner)->postJson('/api/corporate', ['company_name' => 'Owner Corp'])
        ->assertCreated();
});

it('requires a company name to create a corporate account', function () {
    $manager = staffWithRole('Manager');

    $this->actingAs($manager)->postJson('/api/corporate', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_name');
});

it('computes outstanding from corporate-credit charges minus settlements, and supports statement + settle', function () {
    $this->seed(BranchSeeder::class);
    $this->seed(SettingsSeeder::class);
    $this->seed(HotelRoomsSeeder::class);

    $manager = staffWithRole('Manager');
    $account = CorporateAccount::factory()->create(['company_name' => 'Acme Corp']);
    $room = Room::query()->where('number', '102')->firstOrFail();
    $guest = Guest::factory()->create();

    $reservation = $this->actingAs($manager)->postJson('/api/reservations', [
        'guest_id' => $guest->id, 'channel' => 'walkin', 'check_in' => '2026-08-03', 'check_out' => '2026-08-05',
        'adults' => 1, 'rooms' => [['room_id' => $room->id]], 'corporate_account_id' => $account->id,
    ])->assertCreated()->json('reservation');

    $this->actingAs($manager)->postJson("/api/reservations/{$reservation['id']}/check-in", [])->assertOk();

    $folioId = $reservation['folio']['id'];
    $stayTotal = $reservation['rooms'][0]['nightly_rate'] * 2;

    $this->actingAs($manager)->postJson("/api/folios/{$folioId}/payments", [
        'method' => 'corporate_credit', 'amount' => $stayTotal,
    ])->assertCreated();

    $list = $this->actingAs($manager)->getJson('/api/corporate')->assertOk();
    $listed = collect($list->json('corporate_accounts.data'))->firstWhere('id', $account->id);
    expect($listed['outstanding'])->toBe($stayTotal);

    $month = now()->format('Y-m');
    $statement = $this->actingAs($manager)->getJson("/api/corporate/{$account->id}/statement?month={$month}")->assertOk();
    expect($statement->json('total_charges'))->toBe($stayTotal)
        ->and($statement->json('total_settled'))->toBe(0)
        ->and($statement->json('charges'))->toHaveCount(1)
        ->and($statement->json('charges.0.guest'))->toBe($guest->name);

    $this->actingAs($manager)->postJson("/api/corporate/{$account->id}/settle", [
        'amount' => $stayTotal, 'method' => 'bank_transfer', 'reference' => 'TXN-001',
    ])->assertCreated();

    $afterSettle = $this->actingAs($manager)->getJson('/api/corporate')->assertOk();
    $listedAfter = collect($afterSettle->json('corporate_accounts.data'))->firstWhere('id', $account->id);
    expect($listedAfter['outstanding'])->toBe(0);
});

it('rejects settling a corporate account with corporate_credit or loyalty_points', function () {
    $manager = staffWithRole('Manager');
    $account = CorporateAccount::factory()->create();

    $this->actingAs($manager)->postJson("/api/corporate/{$account->id}/settle", [
        'amount' => 100000, 'method' => 'corporate_credit',
    ])->assertUnprocessable()->assertJsonValidationErrors('method');
});
