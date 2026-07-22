<?php

namespace Database\Seeders;

use App\Actions\ProvisionTenant;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // First delete the default tenant created in the migration (since
        // seeders run after migrations).
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        DB::table('tenants')->truncate();
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        // Create a primary tenant for development.
        $tenant = Tenant::create([
            'name' => 'Vellix Resort & Spa',
            'slug' => 'vellix',
            'domain' => 'vellix.localhost',
            'email' => 'admin@vellix.com',
            'phone' => '+1 (555) 123-4567',
            'address' => '123 Ocean View Drive',
            'city' => 'Miami',
            'country' => 'USA',
            'status' => 'active',
            'plan' => 'enterprise',
            'storage_limit_mb' => 10240,
            'max_users' => 100,
        ]);

        // Provision it (super admin, permissions).
        $credentials = app(ProvisionTenant::class)->execute($tenant);

        // Update the super admin to have a known password for local dev.
        $superAdmin = $tenant->users()->first();
        $superAdmin->update([
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'pin_hash' => \Illuminate\Support\Facades\Hash::make('1234'),
        ]);

        // Create a secondary tenant to test isolation.
        $tenant2 = Tenant::create([
            'name' => 'Mountain Lodge',
            'slug' => 'lodge',
            'email' => 'hello@lodge.com',
            'status' => 'active',
            'plan' => 'standard',
            'storage_limit_mb' => 5120,
            'max_users' => 10,
        ]);

        app(ProvisionTenant::class)->execute($tenant2);
    }
}
