<?php

namespace Database\Seeders;

use App\Services\MenuSync;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $sync = new MenuSync;
        $sync->sync();
    }
}
