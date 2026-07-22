<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TenantUsageController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $totalUsers = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $activeUsers = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', User::STATUS_ACTIVE)
            ->count();

        $usersLoggedInToday = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('last_login_at', '>=', now()->startOfDay())
            ->count();

        $auditEventsToday = DB::table('audit_logs')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        $auditEventsThisMonth = DB::table('audit_logs')
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $totalReservations = DB::table('reservations')
            ->where('tenant_id', $tenant->id)
            ->count();

        $totalOrders = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->count();

        $totalRooms = DB::table('rooms')
            ->where('tenant_id', $tenant->id)
            ->count();

        $totalGuests = DB::table('guests')
            ->where('tenant_id', $tenant->id)
            ->count();

        // Login activity over the last 30 days.
        $loginActivity = collect(range(29, 0))->map(function (int $daysAgo) use ($tenant): array {
            $day = now()->subDays($daysAgo)->startOfDay();

            $logins = DB::table('audit_logs')
                ->where('tenant_id', $tenant->id)
                ->where('action', 'login')
                ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
                ->count();

            return [
                'date' => $day->format('M j'),
                'logins' => $logins,
            ];
        })->values()->all();

        return response()->json([
            'metrics' => [
                'total_users' => $totalUsers,
                'active_users' => $activeUsers,
                'max_users' => $tenant->max_users,
                'users_logged_in_today' => $usersLoggedInToday,
                'audit_events_today' => $auditEventsToday,
                'audit_events_this_month' => $auditEventsThisMonth,
                'total_reservations' => $totalReservations,
                'total_orders' => $totalOrders,
                'total_rooms' => $totalRooms,
                'total_guests' => $totalGuests,
                'storage_limit_mb' => $tenant->storage_limit_mb,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
                'last_active_at' => $tenant->last_active_at?->toISOString(),
                'created_at' => $tenant->created_at?->toISOString(),
            ],
            'login_activity' => $loginActivity,
        ]);
    }
}
