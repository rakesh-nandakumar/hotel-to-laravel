<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Same shared demo tenant as UserFactory (see its comment) —
            // keeps IdentifyTenant's "exactly one tenant" local/testing
            // dev-fallback true for the vast majority of tests.
            'tenant_id' => Tenant::demo()->id,
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->sentence(),
            'is_system' => false,
            'is_full_admin' => false,
            'is_active' => true,
        ];
    }
}
