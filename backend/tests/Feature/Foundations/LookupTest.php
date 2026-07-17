<?php

use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\ReservationStatus;
use App\Support\Lookups\RoomStatus;
use Database\Seeders\LookupSeeder;

it('seeds every declared lookup type with at least one active code', function () {
    $this->seed(LookupSeeder::class);

    $types = (new ReflectionClass(LookupType::class))->getConstants();

    foreach ($types as $type) {
        expect(Lookup::query()->type($type)->active()->count())->toBeGreaterThan(0);
    }
});

it('is idempotent — re-running the seeder never duplicates rows', function () {
    $this->seed(LookupSeeder::class);
    $countBefore = Lookup::count();

    $this->seed(LookupSeeder::class);

    expect(Lookup::count())->toBe($countBefore);
});

it('enforces a unique (type, code) pair', function () {
    Lookup::create(['type' => LookupType::ROOM_STATUS, 'code' => RoomStatus::AVAILABLE, 'name' => 'Available']);

    Lookup::create(['type' => LookupType::ROOM_STATUS, 'code' => RoomStatus::AVAILABLE, 'name' => 'Duplicate']);
})->throws(\Illuminate\Database\QueryException::class);

it('resolves and caches a lookup id by (type, code), throwing on an unknown code', function () {
    $this->seed(LookupSeeder::class);

    $id = Lookup::id(LookupType::RESERVATION_STATUS, ReservationStatus::CONFIRMED);

    expect($id)->toBe(Lookup::query()->type(LookupType::RESERVATION_STATUS)->where('code', ReservationStatus::CONFIRMED)->value('id'));

    expect(fn () => Lookup::id(LookupType::RESERVATION_STATUS, 'not-a-real-code'))
        ->toThrow(RuntimeException::class);
});

it('stamps created_by/updated_by like every other model', function () {
    $user = App\Models\User::factory()->create();
    $this->actingAs($user);

    $lookup = Lookup::create(['type' => 'test_type', 'code' => 'x', 'name' => 'X']);

    expect($lookup->created_by)->toBe($user->id)
        ->and($lookup->updated_by)->toBe($user->id);
});
