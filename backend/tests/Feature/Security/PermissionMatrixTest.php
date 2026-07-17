<?php

use App\Models\Permission;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);
});

it('seeds every permission referenced by a can_do route middleware', function () {
    $referenced = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => $route->gatherMiddleware())
        ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'can_do:'))
        ->map(fn ($middleware) => substr($middleware, strlen('can_do:')))
        ->unique()
        ->values();

    $missing = $referenced->diff(Permission::pluck('name'))->values()->all();

    expect($missing)->toBe([], 'Routes reference permissions missing from the seeded matrix: '.implode(', ', $missing));
});

it('seeds every permission referenced by hasPermissionTo/hasAnyPermission in PHP code', function () {
    $referenced = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->flatMap(function ($file) {
            $source = $file->getContents();

            preg_match_all("/hasPermissionTo\\(\\s*'([a-z0-9_]+\\.[a-z0-9_]+)'/", $source, $single);
            preg_match_all('/hasAnyPermission\\(\\[([^\\]]+)\\]/', $source, $multi);

            $fromArrays = collect($multi[1] ?? [])
                ->flatMap(fn ($inner) => preg_match_all("/'([a-z0-9_]+\\.[a-z0-9_]+)'/", $inner, $m) ? $m[1] : []);

            return collect($single[1] ?? [])->merge($fromArrays);
        })
        ->unique()
        ->values();

    $missing = $referenced->diff(Permission::pluck('name'))->values()->all();

    expect($missing)->toBe([], 'PHP code references permissions missing from the seeded matrix: '.implode(', ', $missing));
});

// Frontend permission checks (can()/canAny()) now live in the separate
// decoupled SPA repo, not co-located under resources/js in this API project —
// so they can no longer be scanned from here.

it('does not seed dangling permissions that no route or backend code ever checks', function () {
    $phpSource = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->map(fn ($file) => $file->getContents())
        ->implode("\n");

    $routePermissions = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => $route->gatherMiddleware())
        ->filter(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'can_do:'))
        ->map(fn ($middleware) => substr($middleware, strlen('can_do:')))
        ->unique();

    $dangling = Permission::pluck('name')
        ->reject(fn (string $name) => $routePermissions->contains($name)
            || str_contains($phpSource, "'{$name}'"))
        ->values()
        ->all();

    expect($dangling)->toBe([], 'Seeded permissions are never checked anywhere: '.implode(', ', $dangling));
});
