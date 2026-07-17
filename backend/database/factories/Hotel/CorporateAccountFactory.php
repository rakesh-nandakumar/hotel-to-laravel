<?php

namespace Database\Factories\Hotel;

use App\Models\Hotel\CorporateAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorporateAccount>
 */
class CorporateAccountFactory extends Factory
{
    protected $model = CorporateAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->unique()->company(),
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('07########'),
            'email' => fake()->unique()->companyEmail(),
            'discount_pct' => fake()->randomElement([0, 5, 10, 15]),
            'credit_limit' => 0,
            'active' => true,
        ];
    }
}
