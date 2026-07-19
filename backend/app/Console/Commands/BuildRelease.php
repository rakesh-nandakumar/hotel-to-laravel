<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Dotenv\Dotenv;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Builds ONE deploy-ready zip that serves the whole app — the Laravel API and
 * the compiled React SPA — from a SINGLE domain. You extract it straight into
 * that domain's document root and it works: no second "api." subdomain, no
 * cPanel document-root change, no terminal, no artisan/composer on the server.
 *
 * Layout the zip produces (its entries land directly in the document root):
 *
 *     <document root>/            <- extract the zip HERE
 *       index.php                 patched front controller -> ./app_core
 *       index.html                the React SPA shell (client-side routes)
 *       assets/ sw.js …           built SPA + the backend's public assets
 *       .htaccess                 /api,/sanctum,/broadcasting,/up -> index.php;
 *                                 real files served as-is; else -> index.html
 *       app_core/                 the Laravel core, DENIED to the web
 *         app/ bootstrap/ config/ routes/ database/ vendor/ storage/ .env
 *
 * Why this is same-origin, and why that removes an entire class of bugs: the
 * SPA is built with an EMPTY VITE_API_URL, so it calls "/api/…" and
 * "/sanctum/csrf-cookie" as relative, same-origin URLs (see web/src/lib/api.ts).
 * One origin means no CORS, no cross-site cookies, and no SESSION_DOMAIN /
 * SANCTUM_STATEFUL_DOMAINS juggling across two hosts — the exact "Session store
 * not set" / "CSRF token mismatch" chain the old two-subdomain build fought.
 * validateProductionEnv() still checks the few values that matter for a single
 * host so a bad env file fails loudly here instead of after a deploy.
 *
 * Migrations are NOT run by this bundle: on this shared-hosting workflow the
 * database is loaded by importing a SQL dump (phpMyAdmin), so there is nothing
 * for the server to execute after extracting.
 *
 * Inode strategy (cPanel shared hosting, ~300k inode budget), in order of payoff:
 *   1. Skip the obvious heavyweights at copy time (node_modules, vendor, .git…).
 *   2. Prune the *staged* vendor — tests/docs/CI metadata inside every package.
 *   3. Optionally ship without vendor (--without-vendor) and run composer on the
 *      server instead — but that needs a terminal, so it is off the happy path
 *      for this no-terminal workflow.
 */
class BuildRelease extends Command
{
    protected $signature = 'release:build
        {--name= : Release/zip name prefix (defaults to a slug of the deploy host)}
        {--env-file=.env.production : Production-env file baked in as app_core/.env; relative to the backend root or absolute}
        {--frontend-dir= : Path to the frontend project (defaults to the sibling "web" folder next to this backend)}
        {--core-dir=app_core : Name of the web-denied subfolder that holds the Laravel core inside the document root}
        {--skip-npm : Reuse the existing web/dist instead of rebuilding it}
        {--skip-composer : Reuse the current vendor instead of installing a production-only vendor}
        {--without-vendor : Do not bundle vendor/; run "composer install --no-dev" on the server instead (needs a terminal)}
        {--no-zip : Stage the release but do not create the zip archive}';

    protected $description = 'Build one deploy-ready zip: React SPA + Laravel API on a SINGLE domain — extract into the domain document root, done.';

    private const INODE_BUDGET = 300_000;

    private const INODE_WARN_AT = 280_000;

    /**
     * Whole directories (matched by relative path, "/" separated) that are
     * never copied into the staged Laravel core. Passed to Finder::exclude(),
     * which prunes the subtree before descending into it.
     *
     * NOTE: `public` is excluded here on purpose — its contents are merged into
     * the document root (alongside the built SPA) by copyPublicInto(), not left
     * inside the core. And `database` is NOT excluded: the migration files ship
     * (harmless, and keeps the app inspectable) even though this bundle never
     * runs them — the DB is loaded from a SQL dump.
     *
     * @var list<string>
     */
    private array $excludedDirs = [
        // Handled separately: merged into the document root, not the core.
        'public',
        // Dependencies / build tooling — regenerated or installed separately.
        'node_modules', 'vendor', 'bootstrap/cache',
        // VCS, CI, agent and editor metadata.
        '.git', '.github', '.claude', '.agents', '.codex', '.cursor', '.gemini',
        '.idea', '.vscode', '.zed', '.nova', '.fleet',
        // Tests/docs — not needed at runtime.
        'tests', 'docs',
        // Dev/runtime state — recreated empty in the release.
        'storage',
    ];

    /**
     * Relative paths (from the backend root, "/" separated) excluded as exact
     * matches — for one-off dev artifacts that don't deserve a whole-directory
     * exclusion rule (a bare "database" exclude would also strip migrations).
     *
     * @var list<string>
     */
    private array $excludedRelativePaths = [
        'database/database.sqlite',
        'database/sql',
    ];

    /**
     * Exact filenames (basename) never shipped from the backend.
     *
     * @var list<string>
     */
    private array $excludedFiles = [
        '.env', '.env.example', '.env.backup', '.env.production', 'auth.json',
        '.editorconfig', '.prettierignore', '.prettierrc', '.gitattributes',
        '.gitignore', '.mcp.json', 'boost.json', '.phpactor.json', '.phpunit.result.cache',
        'eslint.config.js', 'tsconfig.json', 'components.json', 'vite.config.js',
        'phpunit.xml', 'package.json', 'package-lock.json',
        'hot',
    ];

    /**
     * Lowercased file extensions never shipped, applied across the backend
     * tree. Runtime text files (robots.txt et al.) are protected via $keepFiles.
     *
     * @var list<string>
     */
    private array $excludedExtensions = [
        'md', 'markdown', 'rst',
        'txt',
        'log', 'map',
        'zip', // stray manual-deploy artifacts some devs drop at the repo root
    ];

    /** @var list<string> */
    private array $keepFiles = [
        'robots.txt', 'humans.txt', 'ads.txt', 'app-ads.txt', 'security.txt',
    ];

    public function handle(): int
    {
        $backendBase = base_path();
        $repoRoot = dirname($backendBase);
        $frontendBase = $this->option('frontend-dir') ?: $repoRoot.'/web';
        $coreDir = trim((string) $this->option('core-dir'), '/') ?: 'app_core';
        $stage = storage_path('app/release/build');
        $stamp = now()->format('Ymd-His');

        // Validation errors are the operator's to fix (a wrong env value), so
        // surface them as a clean CLI error + non-zero exit rather than a stack
        // trace. Build-time I/O failures below are unexpected and still throw.
        try {
            $envFile = $this->resolveEnvFile($backendBase, (string) $this->option('env-file'));
            $env = $this->loadAndValidateProductionEnv($envFile);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $host = $env['host'];
        $name = Str::slug((string) ($this->option('name') ?: $host));
        $zipPath = storage_path("app/release/{$name}-{$stamp}.zip");
        $bundleVendor = ! $this->option('without-vendor');

        $coreStage = $stage.'/'.$coreDir;

        if (! is_dir($frontendBase)) {
            $this->components->error("Frontend project not found at: {$frontendBase}");

            return self::FAILURE;
        }

        if (! $this->option('skip-npm')) {
            // Empty VITE_API_URL => the SPA calls "/api" and "/sanctum" as
            // same-origin RELATIVE URLs (see web/src/lib/api.ts). Passed as a
            // real env var so it wins over web/.env* — Vite lets a VITE_-prefixed
            // process env override .env files. This is what puts both halves on
            // one origin: no CORS, no cross-site cookies, nothing to configure.
            $this->components->task('Building front-end (npm run build)', fn () => $this->runProcess(
                ['npm', 'run', 'build'],
                $frontendBase,
                ['VITE_API_URL' => ''],
            ));
        }

        if (! is_file($frontendBase.'/dist/index.html')) {
            $this->components->error('web/dist/index.html is missing. Run "npm run build" in the frontend project first, or drop --skip-npm.');

            return self::FAILURE;
        }

        $this->components->task('Cleaning staging directory', function () use ($stage) {
            $this->removeTree($stage);
            $this->ensureDir($stage);
        });

        // --- 1) Laravel core -> web-denied ./app_core -----------------------

        $coreFiles = 0;
        $this->components->task("Copying backend core into {$coreDir}/", function () use ($backendBase, $coreStage, &$coreFiles) {
            $coreFiles = $this->copyBackendCore($backendBase, $coreStage);
        });

        $this->components->task('Recreating storage skeleton', fn () => $this->scaffoldStorage($coreStage));

        $this->components->task("Baking production .env into {$coreDir}/", fn () => $this->copyFile($envFile, $coreStage.'/.env'));

        $pruned = 0;

        if (! $bundleVendor) {
            $this->components->info('Vendor not bundled (--without-vendor): run "composer install --no-dev" on the server (needs a terminal).');
        } elseif ($this->option('skip-composer')) {
            $this->components->warn('--skip-composer copies the CURRENT vendor verbatim. If it still contains dev '
                .'dependencies, run "composer install --no-dev --classmap-authoritative" first to avoid shipping them.');
            $this->components->task('Copying current vendor', fn () => $this->copyVendor($backendBase, $coreStage));
            $this->components->task('Pruning vendor cruft', function () use ($coreStage, &$pruned) {
                $pruned = $this->pruneVendor($coreStage.'/vendor');
            });
        } else {
            $this->components->task('Installing production vendor (composer --no-dev)', fn () => $this->runProcess([
                'composer', 'install', '--no-dev', '--optimize-autoloader',
                '--classmap-authoritative', '--no-interaction', '--no-progress', '--no-scripts',
            ], $coreStage));
            $this->components->task('Pruning vendor cruft', function () use ($coreStage, &$pruned) {
                $pruned = $this->pruneVendor($coreStage.'/vendor');
            });
        }

        // --- 2) Document root = backend public + built SPA ------------------

        $publicFiles = 0;
        $this->components->task('Copying backend public assets to document root', function () use ($backendBase, $stage, &$publicFiles) {
            $publicFiles = $this->copyPublicInto($backendBase.'/public', $stage);
        });

        $frontendFiles = 0;
        $this->components->task('Copying compiled front-end to document root', function () use ($frontendBase, $stage, &$frontendFiles) {
            $frontendFiles = $this->copyFrontendDist($frontendBase.'/dist', $stage);
        });

        $this->components->task('Writing single-domain index.php', fn () => $this->writePatchedIndex($stage, $coreDir));

        $this->components->task('Writing single-domain .htaccess', fn () => $this->writeDocrootHtaccess($stage, $coreDir));

        $this->writeDeployNotes($zipPath, $name, $host, $coreDir, $env['db'], $bundleVendor, basename($envFile));

        if ($this->option('no-zip')) {
            $this->components->info("Staged release at: {$stage}");
            $this->components->info("Core files: {$coreFiles}. Vendor pruned: {$pruned}. Public: {$publicFiles}. Frontend: {$frontendFiles}.");

            return self::SUCCESS;
        }

        $zipStats = ['files' => 0, 'dirs' => 0];
        $this->components->task('Creating zip archive', function () use ($stage, $zipPath, &$zipStats) {
            $zipStats = $this->zipDir($stage, $zipPath);
        });

        $this->report($zipPath, $zipStats, $host, $coreDir, $env['db'], $coreFiles, $publicFiles, $frontendFiles, $pruned, $bundleVendor);

        return self::SUCCESS;
    }

    private function resolveEnvFile(string $backendBase, string $option): string
    {
        $path = str_starts_with($option, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $option)
            ? $option
            : $backendBase.'/'.$option;

        if (! is_file($path)) {
            throw new RuntimeException(
                "Production env file not found: {$path}\n"
                .'Create it (gitignored, local-only — see .gitignore) with real production values '
                .'before running release:build. It is baked in as the API\'s .env, so no manual '
                .'server-side editing is ever needed.'
            );
        }

        return $path;
    }

    /**
     * Parse the local production-env file (via phpdotenv's static parser — a
     * plain read, it never touches the app's own runtime env) and verify the
     * values that matter for a SINGLE-domain, same-origin deploy. Everything is
     * derived from one host (APP_URL), so the checks are about that host being
     * consistent with the cookie/session settings rather than reconciling two
     * separate subdomains.
     *
     * @return array{APP_URL: string, host: string, db: string}
     */
    private function loadAndValidateProductionEnv(string $envFile): array
    {
        $env = Dotenv::parse(file_get_contents($envFile));

        $require = fn (string $key) => $env[$key] ?? throw new RuntimeException("{$envFile} is missing required key: {$key}");

        $appEnv = $require('APP_ENV');
        $appDebug = $require('APP_DEBUG');
        $appKey = trim((string) $require('APP_KEY'));
        $appUrl = $require('APP_URL');

        if ($appEnv !== 'production') {
            throw new RuntimeException("APP_ENV must be \"production\" in {$envFile}, got \"{$appEnv}\".");
        }

        if (Str::lower($appDebug) !== 'false' && $appDebug !== '0') {
            throw new RuntimeException("APP_DEBUG must be false in {$envFile} — a debug build leaks stack traces (exactly what caused the earlier 500 error).");
        }

        if ($appKey === '' || $appKey === 'base64:') {
            throw new RuntimeException("APP_KEY is empty in {$envFile} — run \"php artisan key:generate --show\" and paste the value, or the app cannot decrypt sessions/cookies.");
        }

        $host = parse_url($appUrl, PHP_URL_HOST);

        if (! $host || ! str_starts_with($appUrl, 'https://')) {
            throw new RuntimeException("APP_URL must be an https:// URL with a host in {$envFile}, got \"{$appUrl}\". This is the single domain that serves BOTH the SPA and the API.");
        }

        // Sanctum only starts a cookie session for requests whose Origin/Referer
        // host is in SANCTUM_STATEFUL_DOMAINS. On one domain that host IS the
        // deploy host. When the key is omitted, Laravel's default stateful list
        // already includes the APP_URL host, so leaving it unset is fine too.
        $stateful = trim((string) ($env['SANCTUM_STATEFUL_DOMAINS'] ?? ''));
        if ($stateful !== '') {
            $list = array_map('trim', explode(',', $stateful));
            if (! in_array($host, $list, true)) {
                throw new RuntimeException(
                    "SANCTUM_STATEFUL_DOMAINS must include the APP_URL host ({$host}) in {$envFile} — without it "
                    .'Sanctum never starts a session and every login 401s ("Session store not set on request"). '
                    ."For a single domain set: SANCTUM_STATEFUL_DOMAINS={$host}. Got: {$stateful}"
                );
            }
        }

        // The session/XSRF cookie must be readable on the deploy host. A pinned
        // SESSION_DOMAIN has to equal that host or be a parent of it (leading
        // dot); leaving it blank is fine — the cookie is then scoped to the host.
        $sessionDomain = trim((string) ($env['SESSION_DOMAIN'] ?? ''));
        if ($sessionDomain !== '') {
            $bare = ltrim($sessionDomain, '.');
            if ($host !== $bare && ! str_ends_with($host, '.'.$bare)) {
                throw new RuntimeException(
                    "SESSION_DOMAIN ({$sessionDomain}) does not cover the APP_URL host ({$host}) in {$envFile} — "
                    .'the session/XSRF cookie will not be sent back ("CSRF token mismatch"). '
                    ."For a single domain set SESSION_DOMAIN={$host} (or leave it blank)."
                );
            }
        }

        if (Str::lower((string) ($env['SESSION_SECURE_COOKIE'] ?? '')) !== 'true') {
            throw new RuntimeException("SESSION_SECURE_COOKIE must be true in {$envFile} — the deploy host is https://.");
        }

        $db = trim((string) ($env['DB_DATABASE'] ?? ''));
        if ($db === '') {
            throw new RuntimeException("DB_DATABASE is missing in {$envFile} — set the production database name you import the SQL dump into.");
        }

        return [
            'APP_URL' => $appUrl,
            'host' => $host,
            'db' => $db,
        ];
    }

    /**
     * Copy the Laravel core into ./app_core, honouring every exclude rule.
     * Finder prunes excluded directories (including `public`, which is merged
     * into the document root separately) before descending; the filter handles
     * per-file rules (exact names, extensions, runtime keep-list) plus the
     * exact-relative-path excludes a directory-name rule can't express without
     * also swallowing files we need (e.g. `database/migrations`).
     *
     * Empty directories are intentionally NOT recreated — they have no runtime
     * meaning and only burn inodes. The storage skeleton is scaffolded separately.
     *
     * @return int Number of files copied.
     */
    private function copyBackendCore(string $src, string $dst): int
    {
        $finder = Finder::create()
            ->in($src)
            ->files()
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            ->exclude($this->excludedDirs)
            ->filter(fn (SplFileInfo $file): bool => ! $this->isExcludedFile($file));

        $count = 0;
        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if ($this->isExcludedRelativePath($relative)) {
                continue;
            }

            $this->copyFile($file->getPathname(), $dst.'/'.$relative);
            $count++;
        }

        return $count;
    }

    /**
     * Merge the backend's public/ assets into the document root, next to the
     * built SPA. The front controller (index.php) and the .htaccess are written
     * fresh for the single-domain layout, so their originals are skipped; the
     * public/storage entry is skipped too — it is meant to be a symlink, and
     * shipping a stale real copy of it would be misleading.
     *
     * @return int Number of files copied.
     */
    private function copyPublicInto(string $publicSrc, string $docrootStage): int
    {
        if (! is_dir($publicSrc)) {
            throw new RuntimeException("Backend public/ not found at: {$publicSrc}");
        }

        $finder = Finder::create()->in($publicSrc)->files()->ignoreDotFiles(false)
            ->filter(fn (SplFileInfo $file): bool => ! $this->isExcludedFile($file));

        $count = 0;
        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            if (in_array($relative, ['index.php', '.htaccess'], true)) {
                continue;
            }

            if ($relative === 'storage' || str_starts_with($relative, 'storage/')) {
                continue;
            }

            $this->copyFile($file->getPathname(), $docrootStage.'/'.$relative);
            $count++;
        }

        return $count;
    }

    private function isExcludedRelativePath(string $relative): bool
    {
        foreach ($this->excludedRelativePaths as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a single file is excluded. Order matters: the runtime
     * keep-list wins over everything, then exact-name excludes, then extensions.
     */
    private function isExcludedFile(SplFileInfo $file): bool
    {
        $name = $file->getFilename();

        if (in_array($name, $this->keepFiles, true)) {
            return false;
        }

        if (in_array($name, $this->excludedFiles, true)) {
            return true;
        }

        return in_array(strtolower($file->getExtension()), $this->excludedExtensions, true);
    }

    /**
     * Copy the current vendor tree verbatim (used by --skip-composer). The
     * pruneVendor() pass runs afterwards to claw back doc/test cruft.
     */
    private function copyVendor(string $base, string $stage): void
    {
        if (! is_dir($base.'/vendor')) {
            return;
        }

        $finder = Finder::create()->in($base.'/vendor')->files()->ignoreDotFiles(false);

        foreach ($finder as $file) {
            $this->copyFile($file->getPathname(), $stage.'/vendor/'.$file->getRelativePathname());
        }
    }

    /**
     * Copy the frontend's built dist/ tree verbatim into the document root —
     * Vite's output is already minimal (hashed JS/CSS/assets + index.html +
     * sw.js), nothing to prune. Runs AFTER copyPublicInto() so the SPA wins on
     * any filename collision (e.g. index.html, favicon).
     *
     * @return int Number of files copied.
     */
    private function copyFrontendDist(string $dist, string $stage): int
    {
        $finder = Finder::create()->in($dist)->files()->ignoreDotFiles(false);

        $count = 0;
        foreach ($finder as $file) {
            $this->copyFile($file->getPathname(), $stage.'/'.$file->getRelativePathname());
            $count++;
        }

        return $count;
    }

    /**
     * Write the single-domain front controller. It sits in the document root
     * and boots the Laravel core from ./{core}: bootstrap/app.php lives at
     * {core}/bootstrap, so Application's basePath (dirname of that) resolves to
     * {core}, and every Laravel path stays inside the web-denied folder.
     */
    private function writePatchedIndex(string $docrootStage, string $coreDir): void
    {
        $php = <<<PHP
        <?php

        use Illuminate\\Http\\Request;

        define('LARAVEL_START', microtime(true));

        // The Laravel core lives in ./{$coreDir} (denied to the web by .htaccess);
        // only this front controller and the built SPA sit in the document root.
        if (file_exists(\$maintenance = __DIR__.'/{$coreDir}/storage/framework/maintenance.php')) {
            require \$maintenance;
        }

        require __DIR__.'/{$coreDir}/vendor/autoload.php';

        (require_once __DIR__.'/{$coreDir}/bootstrap/app.php')
            ->handleRequest(Request::capture());

        PHP;

        $this->putFile($docrootStage.'/index.php', $php);
    }

    /**
     * Write the document-root .htaccess that makes one folder serve both halves:
     *   - the Laravel core folder is hard-denied (its .env sits there),
     *   - the server-side prefixes go to the Laravel front controller,
     *   - real files (SPA assets, sw.js, images) are served as-is,
     *   - everything else falls back to the SPA shell so client-side (react-
     *     router) deep links resolve instead of Apache 404ing.
     *
     * The prefix list is exhaustive for this app in production: every registered
     * route lives under api/ (Fortify's prefix is `api` too), plus Sanctum's
     * csrf-cookie, broadcasting auth, and the /up health check.
     */
    private function writeDocrootHtaccess(string $docrootStage, string $coreDir): void
    {
        $htaccess = <<<HTACCESS
        # Single-domain bundle: this document root serves the built React SPA and
        # proxies API paths to the Laravel front controller (index.php). The
        # Laravel core lives in ./{$coreDir} and is denied to the web below.

        DirectoryIndex index.html index.php

        <IfModule mod_rewrite.c>
            <IfModule mod_negotiation.c>
                Options -MultiViews -Indexes
            </IfModule>

            RewriteEngine On

            # Pass the Authorization header through to PHP (Laravel default).
            RewriteCond %{HTTP:Authorization} .
            RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

            # Never serve anything out of the Laravel core folder (holds .env).
            RewriteRule ^{$coreDir}(/|\$) - [F,L]

            # Server-side paths -> Laravel front controller.
            RewriteRule ^(api|sanctum|broadcasting|up)(/|\$) index.php [L]

            # Real files/directories (SPA JS/CSS, sw.js, images, favicon) as-is.
            RewriteCond %{REQUEST_FILENAME} -f [OR]
            RewriteCond %{REQUEST_FILENAME} -d
            RewriteRule ^ - [L]

            # Anything else is a client-side (React Router) route -> SPA shell.
            RewriteRule ^ index.html [L]
        </IfModule>

        # Belt-and-suspenders: deny dotenv/VCS dotfiles even if mod_rewrite is off.
        <FilesMatch "^\\.(env|git)">
            Require all denied
        </FilesMatch>

        HTACCESS;

        $this->putFile($docrootStage.'/.htaccess', $htaccess);
    }

    /**
     * Remove non-runtime cruft (tests, docs, examples, CI/lint metadata) from a
     * staged vendor tree. Safe after `composer install --classmap-authoritative`:
     * the frozen classmap never resolves classes outside its indexed files, so
     * deleting test/doc directories cannot break autoloading.
     *
     * Deliberately conservative:
     *   - LICENSE/LICENCE files are NEVER removed (legal requirement).
     *   - .txt is NOT blanket-removed from vendor — third-party packages
     *     occasionally load runtime data from .txt; that risk isn't worth the
     *     handful of inodes it saves. (App-tree .txt removal is fine; it's your code.)
     *
     * @return int Files removed.
     */
    private function pruneVendor(string $vendor): int
    {
        if (! is_dir($vendor)) {
            return 0;
        }

        $before = $this->countFiles($vendor);

        // 1) Non-runtime directories, matched by basename at any depth.
        //    Collect first, then delete deepest-first so we never descend into
        //    a directory that a parent removal has already deleted.
        $dirs = [];
        $dirFinder = Finder::create()->in($vendor)->directories()->ignoreDotFiles(false)
            ->name(['tests', 'Tests', 'test', 'docs', 'doc', 'examples', 'example', 'benchmarks', '.github']);
        foreach ($dirFinder as $dir) {
            $dirs[] = $dir->getPathname();
        }
        usort($dirs, fn (string $a, string $b): int => substr_count($b, DIRECTORY_SEPARATOR) <=> substr_count($a, DIRECTORY_SEPARATOR));
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $this->removeTree($dir);
            }
        }

        // 2) Doc + CI/lint metadata files. LICENSE files are explicitly preserved.
        $files = [];
        $fileFinder = Finder::create()->in($vendor)->files()->ignoreDotFiles(false)
            ->name(['*.md', '*.markdown', '*.rst', '*.dist', '*.map'])
            ->name([
                'phpunit.xml', 'phpstan.neon', 'phpstan.neon.dist', 'psalm.xml',
                'phpcs.xml', 'phpcs.xml.dist', '.editorconfig', '.gitattributes',
                '.styleci.yml', '.scrutinizer.yml',
            ])
            ->notName(['LICENSE*', 'LICENCE*']);
        foreach ($fileFinder as $file) {
            $files[] = $file->getPathname();
        }
        foreach ($files as $file) {
            if (is_file($file) && ! unlink($file)) {
                throw new RuntimeException("Could not remove vendor file: {$file}");
            }
        }

        return max(0, $before - $this->countFiles($vendor));
    }

    private function scaffoldStorage(string $stage): void
    {
        $dirs = [
            'storage/app/public', 'storage/app/private',
            'storage/framework/cache/data', 'storage/framework/sessions',
            'storage/framework/views', 'storage/framework/testing',
            'storage/logs', 'bootstrap/cache',
        ];

        foreach ($dirs as $dir) {
            $this->ensureDir($stage.'/'.$dir);
            $this->putFile($stage.'/'.$dir.'/.gitignore', "*\n!.gitignore\n");
        }
    }

    /**
     * Write the deploy notes NEXT TO the zip (never inside it — a .txt inside
     * the document root would be publicly downloadable). These are the only
     * steps the operator performs, and there is no terminal step on the server.
     */
    private function writeDeployNotes(string $zipPath, string $name, string $host, string $coreDir, string $db, bool $bundleVendor, string $envFileName): void
    {
        $steps = [];
        $steps[] = "In cPanel File Manager, open the document root of {$host}.";
        $steps[] = 'Upload this zip there and Extract it (choose "overwrite" if prompted). '
            ."Its entries land directly in the document root — index.php, index.html, assets/, and the web-denied {$coreDir}/.";
        $steps[] = "Import your MySQL dump into the \"{$db}\" database (phpMyAdmin > Import). "
            .'Only needed the first time, or whenever your data/schema changes — this bundle never runs migrations.';

        if (! $bundleVendor) {
            $steps[] = "vendor/ was NOT bundled (--without-vendor): run \"cd ~/.../{$coreDir} && composer install --no-dev --classmap-authoritative\". "
                .'This needs a terminal — prefer bundling vendor for this no-terminal workflow.';
        }

        $lines = [];
        foreach ($steps as $i => $step) {
            $lines[] = ($i + 1).'. '.$step;
        }

        $title = strtoupper($name).' — single-domain cPanel deploy (SPA + API on one host)';
        $rule = str_repeat('=', strlen($title));

        $notes = $title."\n".$rule."\n\n"
            ."DEPLOY (repeat for every release):\n"
            .implode("\n", $lines)."\n\n"
            ."That's it — no terminal, no artisan, no composer on the server.\n\n"
            ."NOTES\n"
            ."  - Same origin: the SPA calls {$host}/api and {$host}/sanctum/csrf-cookie as\n"
            ."    relative URLs (VITE_API_URL is empty), so there is no CORS or cross-site cookie.\n"
            ."  - {$coreDir}/.env is baked from {$envFileName}. To change the domain, edit APP_URL\n"
            ."    there and rebuild — single source of truth.\n"
            ."  - storage/ is a fresh empty skeleton each build. Sessions & cache live in the DB\n"
            ."    (SESSION_DRIVER=database, CACHE_STORE=database), so nothing important is lost.\n"
            ."  - Public-disk serving (/storage/*) needs a symlink and is NOT set up here; this app\n"
            ."    doesn't rely on it (branding is a data URI, PDFs stream through /api).\n";

        $notesPath = dirname($zipPath).'/DEPLOY-'.$name.'.txt';
        $this->putFile($notesPath, $notes);
    }

    private function report(string $zipPath, array $zipStats, string $host, string $coreDir, string $db, int $coreFiles, int $publicFiles, int $frontendFiles, int $pruned, bool $bundleVendor): void
    {
        $sizeMb = round(filesize($zipPath) / 1_048_576, 1);
        $inodes = $zipStats['files'] + $zipStats['dirs'];
        $budgetPct = round($inodes / self::INODE_BUDGET * 100, 1);

        $this->newLine();
        $this->components->info('Release built successfully.');
        $this->table(['Metric', 'Value'], [
            ['Zip', $zipPath],
            ['Size', "{$sizeMb} MB"],
            ['Deploy host', $host],
            ['Core folder (web-denied)', $coreDir.'/'],
            ['Database (import dump into)', $db],
            ['Backend core files', number_format($coreFiles)],
            ['Vendor files pruned', $bundleVendor ? number_format($pruned) : 'n/a (vendor not bundled)'],
            ['Public assets copied', number_format($publicFiles)],
            ['Frontend files copied', number_format($frontendFiles)],
            ['Files in archive', number_format($zipStats['files'])],
            ['Directories in archive', number_format($zipStats['dirs'])],
            ['Estimated inodes', number_format($inodes).' ('.$budgetPct.'% of budget)'],
            ['Inode budget', number_format(self::INODE_BUDGET)],
        ]);

        $this->newLine();
        $this->components->bulletList([
            "Extract the zip into the {$host} document root (overwrite).",
            "Import your SQL dump into \"{$db}\" (first time / on schema change).",
            'No terminal, no artisan — see the DEPLOY-*.txt next to the zip.',
        ]);

        if ($inodes > self::INODE_WARN_AT) {
            $this->components->warn('Inode count is close to the cPanel limit — review the exclude/prune lists or use --without-vendor.');
        }
    }

    /**
     * @return array{files: int, dirs: int}
     */
    private function zipDir(string $stage, string $zipPath): array
    {
        $this->ensureDir(dirname($zipPath));
        @unlink($zipPath);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create {$zipPath}");
        }

        $files = 0;
        $dirs = 0;
        foreach (Finder::create()->in($stage)->ignoreDotFiles(false) as $item) {
            $relative = str_replace('\\', '/', $item->getRelativePathname());

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                $dirs++;

                continue;
            }

            $zip->addFile($item->getPathname(), $relative);
            $files++;
        }

        $zip->close();

        return ['files' => $files, 'dirs' => $dirs];
    }

    // ---------------------------------------------------------------------
    // Fail-loud filesystem helpers. Native functions return false on error and
    // would otherwise let a half-built release through silently — a release tool
    // is the last place to swallow I/O failures, so every helper throws.
    // ---------------------------------------------------------------------

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create directory: {$path}");
        }
    }

    private function copyFile(string $from, string $to): void
    {
        $this->ensureDir(dirname($to));

        if (! copy($from, $to)) {
            throw new RuntimeException("Could not copy {$from} -> {$to}");
        }
    }

    private function putFile(string $path, string $contents): void
    {
        $this->ensureDir(dirname($path));

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Could not write file: {$path}");
        }
    }

    /**
     * Recursively delete a directory. Returns the number of files removed.
     */
    private function removeTree(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                if (! rmdir($item->getPathname())) {
                    throw new RuntimeException("Could not remove directory: {$item->getPathname()}");
                }
            } elseif (! unlink($item->getPathname())) {
                throw new RuntimeException("Could not remove file: {$item->getPathname()}");
            } else {
                $removed++;
            }
        }

        if (! rmdir($dir)) {
            throw new RuntimeException("Could not remove directory: {$dir}");
        }

        return $removed;
    }

    private function countFiles(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        return iterator_count(Finder::create()->in($dir)->files()->ignoreDotFiles(false));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  Extra env vars, merged over the inherited process env.
     */
    private function runProcess(array $command, string $cwd, array $env = []): void
    {
        $process = new Process($command, $cwd, $env ?: null, null, 1800);
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Command failed: '.implode(' ', $command));
        }
    }
}
