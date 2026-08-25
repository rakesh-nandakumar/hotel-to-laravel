<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Till;
use App\Services\CurrentContext;
use Illuminate\Database\Seeder;

/**
 * One default "Main Till" for the demo tenant — every tenant needs at least
 * one till before staff can open a session and take cash. Idempotent: safe
 * to re-run.
 */
class TillSeeder extends Seeder
{
    public function run(): void
    {
        // Tills are tenant-scoped, and a seeder runs in console with no
        // ambient tenant — bind the demo tenant explicitly (see TenantScope).
        app(CurrentContext::class)->runForTenant(Tenant::demo()->id, function (): void {
            Till::query()->updateOrCreate(
                ['name' => 'Main Till'],
                ['is_active' => true],
            );
        });
    }
}
