<?php

namespace Database\Factories\Hotel;

use App\Models\Hotel\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('07########'),
            'id_number' => fake()->numerify('#########V'),
            'nationality' => 'Sri Lankan',
            'loyalty_points' => 0,
            'lifetime_spend' => 0,
        ];
    }
}
