<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Till;
use Illuminate\Database\Seeder;

/**
 * One default "Main Till" per active branch — every branch needs at least
 * one till before staff can open a session and take cash. Idempotent: safe
 * to re-run.
 */
class TillSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::query()->active()->get() as $branch) {
            Till::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'name' => 'Main Till'],
                ['is_active' => true],
            );
        }
    }
}
