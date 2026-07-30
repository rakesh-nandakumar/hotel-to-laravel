<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Seed the single operating branch, owned by the shared demo tenant (see
     * UserFactory's identical rationale). The template runs single-branch:
     * this "Main Branch" is used implicitly everywhere and the top-bar branch
     * selector stays hidden until a second branch is added.
     */
    public function run(): void
    {
        Branch::query()->withoutTenantScope()->firstOrCreate(
            ['tenant_id' => Tenant::demo()->id, 'name' => 'Main Branch'],
            [
                'is_active' => true,
                'country' => 'Sri Lanka',
            ],
        );
    }
}
