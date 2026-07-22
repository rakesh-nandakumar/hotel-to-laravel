<?php

namespace Database\Seeders\Demo;

use App\Services\Settings;
use Illuminate\Database\Seeder;

/**
 * Flips the handful of business settings that ship at a deliberately inert
 * default (0%) so demo data actually exercises tax/loyalty math. Only
 * touches a setting when it's still exactly at that untouched default —
 * never overwrites a value an admin has since configured for real.
 */
class DemoSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->setIfDefault('billing.vat_pct', 0, 8);
        $this->setIfDefault('billing.service_charge_pct', 0, 10);
        $this->setIfDefault('loyalty.points_per_1000lkr', 0, 10);

        Settings::invalidate();
    }

    private function setIfDefault(string $key, float $untouchedDefault, float $newValue): void
    {
        if (Settings::num($key, $untouchedDefault) === $untouchedDefault) {
            Settings::set($key, $newValue);
        }
    }
}
