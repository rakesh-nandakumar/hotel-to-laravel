<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantPermissionController;
use App\Http\Controllers\Admin\TenantSettingController;
use App\Http\Controllers\Admin\TenantUsageController;
use Illuminate\Support\Facades\Route;

// ── Admin Auth (public) ─────────────────────────────────────────────────────
Route::post('login', [AdminAuthController::class, 'login'])->name('login');

// ── Platform Admin (authenticated) ──────────────────────────────────────────
Route::middleware(['auth:platform'])->group(function () {
    Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::get('me', [AdminAuthController::class, 'me'])->name('me');

    // ── Dashboard ────────────────────────────────────────────────────────────
    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    // ── Tenant CRUD ──────────────────────────────────────────────────────────
    Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');

    // ── Tenant Status ────────────────────────────────────────────────────────
    Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');

    // ── Tenant Permissions ───────────────────────────────────────────────────
    Route::get('tenants/{tenant}/permissions', [TenantPermissionController::class, 'index'])->name('tenants.permissions.index');
    Route::put('tenants/{tenant}/permissions', [TenantPermissionController::class, 'update'])->name('tenants.permissions.update');

    // ── Tenant Settings ──────────────────────────────────────────────────────
    Route::get('tenants/{tenant}/settings', [TenantSettingController::class, 'index'])->name('tenants.settings.index');
    Route::put('tenants/{tenant}/settings/{setting}', [TenantSettingController::class, 'update'])->name('tenants.settings.update');

    // ── Tenant Usage & Metrics ───────────────────────────────────────────────
    Route::get('tenants/{tenant}/usage', [TenantUsageController::class, 'index'])->name('tenants.usage');

    // ── Impersonation ────────────────────────────────────────────────────────
    Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'start'])->name('impersonation.start');
    Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
});
