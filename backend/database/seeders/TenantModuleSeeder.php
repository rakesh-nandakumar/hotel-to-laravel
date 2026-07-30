<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantModule;
use App\Support\ModuleCatalog;
use Illuminate\Database\Seeder;

/**
 * Enables every catalog module for the demo tenant, so a fresh local install
 * has the whole app available out of the box rather than looking half-broken
 * behind master control's module gate (see App\Services\TenantModules).
 */
class TenantModuleSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Tenant::demo()->id;

        foreach (ModuleCatalog::keys() as $moduleKey) {
            TenantModule::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'module_key' => $moduleKey],
                ['is_enabled' => true],
            );
        }
    }
}
