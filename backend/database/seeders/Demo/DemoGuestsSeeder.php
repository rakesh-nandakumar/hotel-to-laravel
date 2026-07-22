<?php

namespace Database\Seeders\Demo;

use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\Guest;
use Illuminate\Database\Seeder;

/**
 * Guest book + negotiated-rate corporate accounts. Guests are created with
 * varied loyalty_points/lifetime_spend up front (rather than left at zero)
 * so the Guests screen shows a realistic mix of first-timers and VIPs even
 * before any reservation history is seeded.
 */
class DemoGuestsSeeder extends Seeder
{
    public const GUEST_COUNT = 32;

    public function run(): void
    {
        if (Guest::query()->count() < self::GUEST_COUNT) {
            Guest::factory()->count(self::GUEST_COUNT - Guest::query()->count())->create()->each(function (Guest $guest, int $i) {
                // Roughly a third are repeat/VIP guests with real history baked in.
                $tier = $i % 3;
                $lifetimeSpend = match ($tier) {
                    0 => fake()->numberBetween(5_000_00, 25_000_00),
                    1 => fake()->numberBetween(60_000_00, 250_000_00),
                    default => 0,
                };
                $guest->update([
                    'lifetime_spend' => $lifetimeSpend,
                    'loyalty_points' => $tier === 1 ? fake()->numberBetween(500, 3000) : ($tier === 0 ? fake()->numberBetween(0, 400) : 0),
                    'nationality' => fake()->randomElement(['Sri Lankan', 'Sri Lankan', 'Sri Lankan', 'British', 'Indian', 'Australian', 'German']),
                ]);
            });
        }

        collect([
            ['company_name' => 'Ceylon Tea Traders (Pvt) Ltd', 'discount_pct' => 10, 'credit_limit' => 500_000_00],
            ['company_name' => 'Colombo Business Solutions', 'discount_pct' => 5, 'credit_limit' => 250_000_00],
            ['company_name' => 'Serendib Freight & Logistics', 'discount_pct' => 15, 'credit_limit' => 750_000_00],
            ['company_name' => 'Lanka Gem Exporters Association', 'discount_pct' => 10, 'credit_limit' => 300_000_00],
            ['company_name' => 'Horizon Tech Ventures', 'discount_pct' => 0, 'credit_limit' => 0],
        ])->each(function (array $attrs) {
            CorporateAccount::query()->firstOrCreate(
                ['company_name' => $attrs['company_name']],
                array_merge(CorporateAccount::factory()->make()->toArray(), $attrs),
            );
        });
    }
}
