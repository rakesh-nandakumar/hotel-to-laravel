<?php

namespace App\Http\Controllers;

use Database\Seeders\MenusAndPermissionsSeeder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Deploy utilities for hosts where shell/SSH access isn't available to run
 * `php artisan migrate` / `db:seed` directly — the release:build bundle is
 * extracted through cPanel's File Manager and there is no terminal after that.
 *
 * Five routes, each registered twice (see routes/api.php for the /api/deploy/*
 * form and bootstrap/app.php's withRouting(then: ...) for the bare form):
 *
 *     GET /migrate/status   what has and hasn't run — read-only, changes nothing
 *     GET /migrate          run the pending migrations
 *     GET /seed             run every seeder (DatabaseSeeder)
 *     GET /seed/menus       run only MenuSeeder + PermissionsAndRolesSeeder
 *     GET /migrate/menu     sync menu items, derive permissions, re-sync system roles, flush caches
 *
 * /seed/menus exists because a full /seed also replays AdminUsersSeeder,
 * LookupSeeder, SettingsSeeder, HotelRoomsSeeder etc. — overkill (and slower)
 * when only the menu/permission definitions changed and you just want the
 * new menu items, derived permissions and system roles picked up.
 *
 * /migrate/menu is the same as /seed/menus but runs the dedicated menu:sync
 * command, which also handles per-tenant system role syncs and cache flushing.
 *
 * The bare (unprefixed) form only reaches Laravel if the server sends it there:
 * both the release bundle's .htaccess (BuildRelease::writeDocrootHtaccess) and
 * the Docker nginx config (web/nginx.conf) list `migrate|seed` alongside
 * api/sanctum/broadcasting/up, otherwise the path falls through to the SPA's
 * index.html and the React app comes back instead — that regex matches
 * `seed/menus` and `migrate/menu` too (prefix followed by `/`), so no separate
 * .htaccess entry is needed for them, only matching nginx `location` blocks.
 * `migrate` and `seed` are reserved tenant slugs for the same reason
 * (App\Rules\ReservedSlug); a reservation on `seed` already covers every path
 * under it.
 *
 * Responses are plain text, not JSON, so the artisan output is readable as-is
 * in a browser tab rather than one long escaped "\n" string.
 *
 * WARNING: deliberately public — no auth, no CSRF, no token. Anyone who
 * discovers a URL can run migrations/seeders against this environment.
 * Requested as-is; remove or gate behind a secret once SSH access exists.
 */
class DeployController extends Controller
{
    /**
     * Read-only: prints the migration table (Ran? / Migration / Batch) so you
     * can see what a /migrate would apply before applying it.
     */
    public function status(): Response
    {
        return $this->run('migrate:status', [], 'deploy/migrate/status');
    }

    public function migrate(): Response
    {
        return $this->run('migrate', ['--force' => true], 'deploy/migrate');
    }

    /**
     * Every seeder in DatabaseSeeder is idempotent (firstOrCreate/updateOrCreate),
     * and AdminUsersSeeder/CentralAdminSeeder both no-op in production, so this
     * is safe to hit repeatedly on the live environment — it only ever fills in
     * reference data (menu/permissions/lookups/settings/demo till), never
     * resets real accounts.
     */
    public function seed(): Response
    {
        return $this->run('db:seed', ['--force' => true], 'deploy/seed');
    }

    /**
     * MenuSeeder and PermissionsAndRolesSeeder are both sync-style
     * (create/update what's defined, remove what's gone), so — like seed()
     * above — this is safe to hit repeatedly on the live environment: it only
     * ever re-syncs menu items, their derived permissions and the system
     * roles built from them, never user accounts or other reference data.
     */
    public function seedMenusAndPermissions(): Response
    {
        return $this->run('db:seed', [
            '--class' => MenusAndPermissionsSeeder::class,
            '--force' => true,
        ], 'deploy/seed/menus');
    }

    /**
     * Syncs menu items, derives permissions, re-syncs system roles for all tenants,
     * and flushes permission caches. This is safe to hit repeatedly on the live
     * environment: it only ever re-syncs menu items, their derived permissions
     * and the system roles built from them, never user accounts or other reference data.
     */
    public function menu(): Response
    {
        return $this->run('menu:sync', [], 'deploy/migrate/menu');
    }

    /**
     * Runs the artisan command the way a shared host's PHP-over-HTTP can
     * actually survive it:
     *
     *   - no time limit — a batch of migrations easily outruns cPanel's
     *     default 30s max_execution_time, and a batch cut off half-way leaves
     *     the migrations table claiming steps that never ran;
     *   - keep going if the browser gives up (ignore_user_abort), for the
     *     same reason;
     *   - never prompt — there is no STDIN in a web SAPI, so any question the
     *     command asks (migrate offers to create a missing database) would
     *     crash instead of using its default answer.
     *
     * @param  array<string, mixed>  $options
     */
    private function run(string $command, array $options, string $logLabel): Response
    {
        @set_time_limit(0);
        ignore_user_abort(true);

        $exitCode = Artisan::call($command, $options + ['--no-interaction' => true]);
        $output = trim(Artisan::output());

        Log::warning("Public {$logLabel} route invoked", ['ip' => request()->ip(), 'exit_code' => $exitCode]);

        $rule = str_repeat('-', 60);
        $body = "$ php artisan {$command}\n{$rule}\n"
            .($output === '' ? '(no output)' : $output)
            ."\n{$rule}\n"
            .($exitCode === 0 ? 'OK' : 'FAILED')." (exit code {$exitCode})\n";

        // no-store: artisan output is a one-off — nothing (browser, service
        // worker, edge cache) may ever replay it for a later visit to this URL.
        return response($body, $exitCode === 0 ? 200 : 500)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private');
    }
}
