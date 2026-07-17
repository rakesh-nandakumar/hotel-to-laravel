<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Seed the single operating branch. The template runs single-branch: this
     * "Main Branch" is used implicitly everywhere and the top-bar branch
     * selector stays hidden until a second branch is added.
     */
    public function run(): void
    {
        Branch::firstOrCreate(
            ['name' => 'Main Branch'],
            [
                'is_active' => true,
                'country' => 'Sri Lanka',
            ],
        );
    }
}
