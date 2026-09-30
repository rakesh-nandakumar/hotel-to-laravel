<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantDataPurge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDataPurge>
 */
class TenantDataPurgeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'status' => TenantDataPurge::STATUS_COMPLETED,
            'selections' => [['key' => 'restaurant_orders', 'filters' => []]],
            'summary' => ['total_rows' => 0, 'tables' => []],
            'fingerprint' => hash('sha256', fake()->uuid()),
            'continue_numbering' => false,
            'total_rows' => 0,
            'expires_at' => now()->addDays(90),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantDataPurge::STATUS_EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
    }
}
