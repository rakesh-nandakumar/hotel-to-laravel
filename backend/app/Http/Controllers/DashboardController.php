<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', User::STATUS_ACTIVE)->count(),
            'total_roles' => Role::where('is_active', true)->count(),
            'events_today' => AuditLog::where('created_at', '>=', now()->startOfDay())->count(),
        ];

        $recentActivity = AuditLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actor
                    ? ['name' => $log->actor->name, 'email' => $log->actor->email]
                    : null,
                'created_at' => $log->created_at?->toISOString(),
            ])
            ->all();

        // Event volume over the last 14 days for a small trend chart.
        $since = now()->subDays(13)->startOfDay();
        $counts = AuditLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $activityChart = collect(range(13, 0))->map(function (int $daysAgo) use ($counts): array {
            $day = now()->subDays($daysAgo)->startOfDay();

            return [
                'date' => $day->format('M j'),
                'events' => (int) ($counts[$day->toDateString()] ?? 0),
            ];
        })->values()->all();

        return response()->json([
            'stats' => $stats,
            'recent_activity' => $recentActivity,
            'activity_chart' => $activityChart,
        ]);
    }
}
