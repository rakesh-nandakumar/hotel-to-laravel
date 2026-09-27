<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentContext;
use App\Services\MenuSync;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Console\Command;

class SyncMenu extends Command
{
    protected $signature = 'menu:sync';

    protected $description = 'Sync menu items, derive permissions, re-sync system roles for all tenants, and flush permission caches';

    public function handle(): int
    {
        $this->info('Syncing menu items...');
        $sync = new MenuSync;
        $stats = $sync->sync();

        $this->info("Menu sync completed: {$stats['created']} created, {$stats['updated']} updated, {$stats['removed']} removed");

        $this->info('Deriving permissions from menu...');
        $seeder = new PermissionsAndRolesSeeder;
        $seeder->derivePermissionsFromMenu();

        $this->info('Re-syncing system roles for all tenants...');
        Tenant::query()->lazy()->each(function (Tenant $tenant) use ($seeder): void {
            $this->line("  Syncing roles for tenant: {$tenant->slug}");
            $seeder->seedSystemRoles($tenant->id);
        });

        $this->info('Flushing permission caches for all users...');
        app(CurrentContext::class)->runWithoutTenant(function (): void {
            User::query()->whereNotNull('role_id')->lazy()->each(function (User $user): void {
                $user->flushPermissionCache();
            });
        });

        $this->info('Menu sync completed successfully.');

        return self::SUCCESS;
    }
}
