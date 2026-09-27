<?php

namespace Database\Seeders;

use App\Models\Hotel\Venue;
use App\Models\Tenant;
use App\Services\CurrentContext;
use Illuminate\Database\Seeder;

class MountviewVenueSeeder extends Seeder
{
    /**
     * Seed Mountview's venue configuration (Lower Hall and Upper Hall with pricing).
     * This seeder must be run for the Mountview tenant specifically.
     *
     * Run with: php artisan db:seed --class=MountviewVenueSeeder
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'mountview')->firstOrFail();

        app(CurrentContext::class)->runForTenant($tenant, function () {
            // Create or update Lower Hall (Luxury)
            $lowerHall = Venue::updateOrCreate(
                ['name' => 'Lower Hall (Luxury)'],
                [
                    'max_capacity' => 500,
                    'luxury_hall_charge' => 4500000, // 45,000 LKR
                    'per_plate_starting_price' => 195000, // 1,950 LKR
                    'hall_only_per_person' => 50000, // 500 LKR
                    'dj_included' => true,
                    'bar_charges_included' => true,
                    'byod_allowed' => true,
                    'use_package_pricing' => true,
                    'default_charge_defaults' => [
                        'ac' => 12000,
                        'table' => 10000,
                        'cleaning_staff' => 2500,
                        'service_supply' => 6000,
                        'water' => 2000,
                        'light' => 7500,
                        'dj' => 11000,
                        'water_cleaning' => 6000,
                        'other' => 1000,
                    ],
                ]
            );
            $this->command->info($lowerHall->wasRecentlyCreated ? 'Created Lower Hall (Luxury)' : 'Updated Lower Hall (Luxury)');

            // Create or update Upper Hall (Standard)
            $upperHall = Venue::updateOrCreate(
                ['name' => 'Upper Hall (Standard)'],
                [
                    'max_capacity' => 500,
                    'basic_hall_charge' => 4000000, // 40,000 LKR
                    'per_plate_starting_price' => 195000, // 1,950 LKR
                    'hall_only_per_person' => 50000, // 500 LKR
                    'dj_included' => true,
                    'bar_charges_included' => true,
                    'byod_allowed' => true,
                    'use_package_pricing' => true,
                    'default_charge_defaults' => [
                        'ac' => 10000,
                        'table' => 7500,
                        'cleaning_staff' => 2500,
                        'service_supply' => 4000,
                        'water' => 1000,
                        'light' => 8000,
                        'dj' => 11000,
                        'water_cleaning' => 5000,
                        'other' => 1000,
                    ],
                ]
            );
            $this->command->info($upperHall->wasRecentlyCreated ? 'Created Upper Hall (Standard)' : 'Updated Upper Hall (Standard)');

            $this->command->info('Mountview venue configuration seeded successfully');
        });
    }
}
