<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantStatus;
use Illuminate\Http\JsonResponse;

/**
 * Master-control overview — the same numbers leolanka's central dashboard
 * widgets show: tenant counts by lifecycle status, live vs test instances,
 * recent activity, and the size of the platform.
 */
class CentralDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $tenants = Tenant::query();

        $statusCounts = (clone $tenants)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $environmentCounts = (clone $tenants)
            ->selectRaw('environment, count(*) as total')
            ->groupBy('environment')
            ->pluck('total', 'environment');

        $recent = (clone $tenants)
            ->withCount(['branches', 'users'])
            ->latest()
            ->limit(6)
            ->get();

        return response()->json([
            'counts' => [
                'total' => (clone $tenants)->count(),
                'by_status' => [
                    'trial' => (int) $statusCounts->get(TenantStatus::TRIAL, 0),
                    'active' => (int) $statusCounts->get(TenantStatus::ACTIVE, 0),
                    'suspended' => (int) $statusCounts->get(TenantStatus::SUSPENDED, 0),
                    'cancelled' => (int) $statusCounts->get(TenantStatus::CANCELLED, 0),
                ],
                'by_environment' => [
                    'live' => (int) $environmentCounts->get(Tenant::ENV_LIVE, 0),
                    'test' => (int) $environmentCounts->get(Tenant::ENV_TEST, 0),
                ],
                'admins' => CentralAdmin::query()->count(),
                'users' => User::query()->withoutTenantScope()->count(),
                'branches' => Branch::query()->withoutTenantScope()->count(),
            ],
            'recent_tenants' => $recent,
        ]);
    }
}
