<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::active()->count();
        $suspendedTenants = Tenant::suspended()->count();

        $totalUsers = User::withoutGlobalScopes()->count();
        $activeUsers = User::withoutGlobalScopes()->where('status', User::STATUS_ACTIVE)->count();

        // New tenants in the last 30 days
        $newTenantsThisMonth = Tenant::where('created_at', '>=', now()->subDays(30))->count();

        // Storage usage across all tenants
        $totalStorageLimitMb = Tenant::sum('storage_limit_mb');
        // In a real app we'd measure actual file sizes, here we'll mock a usage metric for the dashboard
        $estimatedStorageUsedMb = round($totalUsers * 2.5); // Mock estimate based on user count

        return response()->json([
            'metrics' => [
                'total_tenants' => $totalTenants,
                'active_tenants' => $activeTenants,
                'suspended_tenants' => $suspendedTenants,
                'new_tenants_30d' => $newTenantsThisMonth,
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'total_storage_limit_mb' => $totalStorageLimitMb,
                'estimated_storage_used_mb' => $estimatedStorageUsedMb,
            ],
        ]);
    }
}
