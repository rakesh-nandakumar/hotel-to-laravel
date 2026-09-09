<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Deploy utilities for hosts where shell/SSH access isn't available to run
 * `php artisan migrate` / `db:seed` directly. Deliberately public (no auth,
 * no CSRF, no token) — see routes/api.php and bootstrap/app.php (the bare
 * /migrate + /seed routes, which also need an nginx proxy rule since
 * everything outside /api/, /sanctum/, /broadcasting/ otherwise falls
 * through to the SPA — see web/nginx.conf) and the IdentifyTenant bypass in
 * app/Http/Middleware/IdentifyTenant.php for the prefixed /api/deploy/*
 * versions. WARNING: this means anyone who discovers a URL can run
 * migrations/seeders against this environment. Requested as-is; remove or
 * gate behind a secret once SSH access exists.
 */
class DeployController extends Controller
{
    public function migrate(): JsonResponse
    {
        return $this->run('migrate', 'deploy/migrate');
    }

    /**
     * Every seeder in DatabaseSeeder is idempotent (firstOrCreate/updateOrCreate),
     * and AdminUsersSeeder/CentralAdminSeeder both no-op in production, so this
     * is safe to hit repeatedly on the live environment — it only ever fills in
     * reference data (menu/permissions/lookups/settings/demo till), never
     * resets real accounts.
     */
    public function seed(): JsonResponse
    {
        return $this->run('db:seed', 'deploy/seed');
    }

    private function run(string $command, string $logLabel): JsonResponse
    {
        $exitCode = Artisan::call($command, ['--force' => true]);
        $output = Artisan::output();

        Log::warning("Public {$logLabel} route invoked", ['ip' => request()->ip(), 'exit_code' => $exitCode]);

        return response()->json([
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
        ], $exitCode === 0 ? 200 : 500);
    }
}
