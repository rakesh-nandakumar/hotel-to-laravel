<?php

namespace Database\Seeders;

use App\Models\Hotel\Package;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\RoomStatus;
use Illuminate\Database\Seeder;

/**
 * Demo/reference data ported 1:1 from the Node app's src/seed.ts (§8) —
 * room types, rooms, a sample seasonal rate, and the meal packages.
 * Idempotent: safe to re-run, existing rows are left untouched.
 */
class HotelRoomsSeeder extends Seeder
{
    private const DEFAULT_AMENITIES = ['AC', 'TV', 'WiFi', 'Hot water'];

    private const ITEM_CHECKLIST = [
        'Bed linen, pillows & cushions',
        'Bath towels, hand towels & face towels',
        'TV & remote control',
        'AC unit & remote control',
        'Hangers',
        'Electric kettle & cups/glasses',
        'Toiletries (soap, shampoo, toilet paper)',
        'Slippers',
        'Minibar contents (if applicable)',
        'In-room safe (if applicable)',
        'Curtains & window fittings',
        'Light bulbs / lamps functioning',
        'Bathroom fittings (shower, tap, flush) in working order',
        'WiFi info card / Do Not Disturb sign',
    ];

    private const CLEANING_CHECKLIST = [
        'Strip used linen and remake bed with fresh linen',
        'Replace used towels with fresh ones',
        'Dust all surfaces, furniture, and fittings',
        'Vacuum/mop the floor',
        'Clean bathroom — toilet, shower/tub, sink, mirror',
        'Restock toiletries and guest amenities',
        'Empty and reline trash bins',
        'Restock/check minibar items',
        'Clean windows, mirrors, and glass surfaces',
        'Check AC, TV, and lights are functioning',
        'Check for and log any damage or maintenance issue found',
        'Final inspection and mark room status as Clean/Ready in the system',
    ];

    /**
     * @var list<array{name: string, max_occupancy: int, weekday: int, weekend: int, rooms: list<string>}>
     */
    private const ROOM_TYPES = [
        ['name' => 'Family 4-Person', 'max_occupancy' => 4, 'weekday' => 18000, 'weekend' => 22000, 'rooms' => ['110', '111', '112']],
        ['name' => 'Family Special', 'max_occupancy' => 5, 'weekday' => 25000, 'weekend' => 30000, 'rooms' => ['101']],
        ['name' => 'Two-Person Room', 'max_occupancy' => 2, 'weekday' => 12000, 'weekend' => 15000, 'rooms' => ['102', '103', '104', '105', '115', '116']],
        ['name' => 'Special Couple Room', 'max_occupancy' => 2, 'weekday' => 16000, 'weekend' => 20000, 'rooms' => ['114']],
        ['name' => 'Triple Room', 'max_occupancy' => 3, 'weekday' => 15000, 'weekend' => 18000, 'rooms' => ['106', '107']],
    ];

    public function run(): void
    {
        $availableStatusId = Lookup::id(LookupType::ROOM_STATUS, RoomStatus::AVAILABLE);

        foreach (\App\Models\Tenant::all() as $tenant) {
            foreach (self::ROOM_TYPES as $definition) {
                $roomType = RoomType::query()->firstOrCreate(
                    ['name' => $definition['name'], 'tenant_id' => $tenant->id],
                    [
                        'max_occupancy' => $definition['max_occupancy'],
                        'bed_config' => 'TBC — pending from owner',
                        'amenities' => self::DEFAULT_AMENITIES,
                        'weekday_rate' => $definition['weekday'] * 100,
                        'weekend_rate' => $definition['weekend'] * 100,
                        'item_checklist' => self::ITEM_CHECKLIST,
                        'cleaning_checklist' => self::CLEANING_CHECKLIST,
                    ],
                );

                $roomType->seasonalRates()->firstOrCreate(
                    ['name' => 'December Peak', 'tenant_id' => $tenant->id],
                    [
                        'start_date' => '2026-12-15',
                        'end_date' => '2027-01-05',
                        'rate' => (int) round($definition['weekend'] * 100 * 1.2),
                    ],
                );

                foreach ($definition['rooms'] as $number) {
                    Room::query()->firstOrCreate(
                        ['number' => $number, 'tenant_id' => $tenant->id],
                        [
                            'room_type_id' => $roomType->id,
                            'floor' => str_starts_with($number, '11') ? 'Upper' : 'Ground',
                            'view' => 'Hill view',
                            'amenities' => self::DEFAULT_AMENITIES,
                            'room_status_id' => $availableStatusId,
                        ],
                    );
                }
            }
        }

        foreach (\App\Models\Tenant::all() as $tenant) {
            foreach ($this->packages() as $package) {
                Package::query()->firstOrCreate(['code' => $package['code'], 'tenant_id' => $tenant->id], $package);
            }
        }
    }

    /**
     * @return list<array{code: string, name: string, price_per_person_per_night: int, meal_inclusions: list<string>}>
     */
    private function packages(): array
    {
        return [
            ['code' => 'RO', 'name' => 'Room Only', 'price_per_person_per_night' => 0, 'meal_inclusions' => []],
            ['code' => 'BB', 'name' => 'Bed & Breakfast', 'price_per_person_per_night' => 1500 * 100, 'meal_inclusions' => ['Breakfast']],
            ['code' => 'HB', 'name' => 'Half Board', 'price_per_person_per_night' => 3500 * 100, 'meal_inclusions' => ['Breakfast', 'Dinner']],
            ['code' => 'FB', 'name' => 'Full Board', 'price_per_person_per_night' => 5000 * 100, 'meal_inclusions' => ['Breakfast', 'Lunch', 'Dinner']],
        ];
    }
}
