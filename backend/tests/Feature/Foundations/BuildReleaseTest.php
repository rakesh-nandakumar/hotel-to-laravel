<?php

use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;

/**
 * Write a temp production-env file for release:build, starting from a valid
 * single-domain baseline and applying overrides (a null value drops the key).
 */
function releaseEnvFile(array $overrides = []): string
{
    $env = array_merge([
        'APP_NAME' => 'Test',
        'APP_ENV' => 'production',
        'APP_KEY' => 'base64:uxaHvfk2lgQj+HeuaxeOY6NUPmOS74TXJ6k6Yi1s0yk=',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://hotel.example.com',
        'SESSION_DOMAIN' => 'hotel.example.com',
        'SESSION_SECURE_COOKIE' => 'true',
        'SANCTUM_STATEFUL_DOMAINS' => 'hotel.example.com',
        'DB_DATABASE' => 'hotel_prod',
    ], $overrides);

    $lines = [];
    foreach ($env as $key => $value) {
        if ($value !== null) {
            $lines[] = "{$key}={$value}";
        }
    }

    $path = sys_get_temp_dir().'/rel-'.uniqid().'.env';
    File::put($path, implode("\n", $lines)."\n");

    return $path;
}

/**
 * Stage-only build (no npm, no vendor, no zip) — fast, and exercises the whole
 * single-domain assembly against the real app tree.
 *
 * @param  array<string, mixed>  $extra
 */
function stageRelease(string $envFile, array $extra = []): PendingCommand
{
    return test()->artisan('release:build', array_merge([
        '--env-file' => $envFile,
        '--skip-npm' => true,
        '--without-vendor' => true,
        '--no-zip' => true,
    ], $extra));
}

// Str::slug drops the dots, so "hotel.example.com" -> "hotelexamplecom".
const DEPLOY_SLUG = 'hotelexamplecom';

afterEach(function () {
    // Don't let the staged copy of the app tree pile up between tests.
    File::deleteDirectory(storage_path('app/release/build'));
    File::delete(storage_path('app/release/DEPLOY-'.DEPLOY_SLUG.'.txt'));
});

it('assembles a single-domain bundle: patched index.php, split .htaccess, core quarantined in app_core', function () {
    stageRelease(releaseEnvFile())->assertSuccessful();

    $stage = storage_path('app/release/build');

    // Document root holds the built SPA (from web/dist), merged with public.
    expect(File::exists("{$stage}/index.html"))->toBeTrue()
        ->and(File::isDirectory("{$stage}/assets"))->toBeTrue();

    // Front controller boots Laravel from ./app_core, not ./ .
    expect(File::get("{$stage}/index.php"))
        ->toContain("__DIR__.'/app_core/vendor/autoload.php'")
        ->toContain("__DIR__.'/app_core/bootstrap/app.php'");

    // .htaccess: core denied, server prefixes -> Laravel, else -> SPA shell.
    expect(File::get("{$stage}/.htaccess"))
        ->toContain('DirectoryIndex index.html index.php')
        ->toContain('RewriteRule ^app_core(/|$) - [F,L]')
        ->toContain('RewriteRule ^(api|sanctum|broadcasting|up)(/|$) index.php [L]')
        ->toContain('RewriteRule ^ index.html [L]');

    // Laravel core is inside app_core/, its env is baked, and public/ is NOT
    // duplicated there (it was merged into the document root instead).
    expect(File::isDirectory("{$stage}/app_core/app"))->toBeTrue()
        ->and(File::isDirectory("{$stage}/app_core/public"))->toBeFalse()
        ->and(File::get("{$stage}/app_core/.env"))->toContain('APP_URL=https://hotel.example.com');
});

it('does not bundle vendor when --without-vendor is set', function () {
    stageRelease(releaseEnvFile())->assertSuccessful();

    expect(File::isDirectory(storage_path('app/release/build/app_core/vendor')))->toBeFalse();
});

it('writes deploy notes next to the zip, never inside the document root', function () {
    stageRelease(releaseEnvFile())->assertSuccessful();

    $stage = storage_path('app/release/build');
    $notes = 'DEPLOY-'.DEPLOY_SLUG.'.txt';

    expect(File::exists(storage_path('app/release/'.$notes)))->toBeTrue()
        ->and(File::exists("{$stage}/{$notes}"))->toBeFalse();
});

it('rejects a leftover two-subdomain env where stateful domains miss the API host', function () {
    stageRelease(releaseEnvFile([
        'APP_URL' => 'https://api.hotel.example.com',       // host is api.hotel.example.com
        'SANCTUM_STATEFUL_DOMAINS' => 'hotel.example.com',  // ...which is not in this list
    ]))->assertFailed();
});

it('rejects a debug build', function () {
    stageRelease(releaseEnvFile(['APP_DEBUG' => 'true']))->assertFailed();
});

it('rejects an empty APP_KEY', function () {
    stageRelease(releaseEnvFile(['APP_KEY' => '']))->assertFailed();
});

it('rejects a non-secure session cookie on an https host', function () {
    stageRelease(releaseEnvFile(['SESSION_SECURE_COOKIE' => 'false']))->assertFailed();
});

it('rejects a missing database name', function () {
    stageRelease(releaseEnvFile(['DB_DATABASE' => null]))->assertFailed();
});
