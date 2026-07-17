<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLog as AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    private function fullAdminIds(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('is_full_admin', true)->where('is_active', true))
            ->pluck('id');
    }

    /**
     * Apply the shared set of list filters (action, actor, date range, search)
     * used by both the index view and the CSV export so they always agree on
     * what "the current view" contains.
     *
     * @return Builder<AuditLog>
     */
    private function filteredQuery(Request $request): Builder
    {
        return AuditLog::query()
            ->with(['actor:id,name,email', 'actor.roles:id,name'])
            ->whereNotIn('actor_id', $this->fullAdminIds())
            ->when($request->input('actions'), fn ($q, $actions) => $q->whereIn('action', (array) $actions))
            ->when($request->input('actor_id'), fn ($q, $id) => $q->where('actor_id', $id))
            ->when($request->input('entity'), fn ($q, $entity) => $q->where('subject_type', $entity))
            ->when($request->input('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->input('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->input('search'), fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('action', 'like', '%'.$s.'%')
                    ->orWhere('description', 'like', '%'.$s.'%')
                    ->orWhere('subject_type', 'like', '%'.$s.'%');
            }));
    }

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('audit_logs.access')) {
            abort(403);
        }

        $sortable = ['created_at', 'action'];
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, $sortable, true)) {
            $sort = 'created_at';
            $direction = 'desc';
        }

        $logs = $this->filteredQuery($request)
            ->orderBy($sort, $direction)
            ->paginate($request->integer('page_size', 50))
            ->withQueryString()
            ->through(fn ($log) => array_merge($log->toArray(), [
                'description' => $log->description ?: AuditLogService::describe($log),
            ]));

        $actorOptions = User::query()
            ->select(['id', 'name', 'email'])
            ->whereNotIn('id', $this->fullAdminIds())
            ->orderBy('name', 'asc')
            ->limit(100)
            ->get();

        $availableActions = AuditLog::query()
            ->select(['action'])
            ->distinct()
            ->orderBy('action', 'asc')
            ->pluck('action');

        $availableEntities = AuditLog::query()
            ->select(['subject_type'])
            ->whereNotNull('subject_type')
            ->distinct()
            ->pluck('subject_type')
            ->map(fn (string $fqcn) => ['value' => $fqcn, 'label' => class_basename($fqcn)])
            ->sortBy('label')
            ->values();

        return response()->json([
            'logs' => $logs,
            'actorOptions' => $actorOptions,
            'availableActions' => $availableActions,
            'availableEntities' => $availableEntities,
            'filters' => [
                'actions' => (array) $request->input('actions', []),
                'actor_id' => $request->input('actor_id'),
                'entity' => $request->input('entity'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'search' => $request->input('search'),
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        if (! $request->user()->hasPermissionTo('audit_logs.view')) {
            abort(403);
        }

        $auditLog->load(['actor:id,name,email', 'actor.roles:id,name']);

        return response()->json([
            'log' => array_merge($auditLog->toArray(), [
                'description' => $auditLog->description ?: AuditLogService::describe($auditLog),
            ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        if (! $request->user()->hasPermissionTo('audit_logs.export')) {
            abort(403);
        }

        $logs = $this->filteredQuery($request)->orderByDesc('created_at')->limit(1000)->get();

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Actor', 'Email', 'Action', 'Description', 'IP', 'Subject Type', 'Subject ID']);
            foreach ($logs as $log) {
                fputcsv($out, [
                    $log->created_at?->toDateTimeString(),
                    $log->actor?->name ?? 'System',
                    $log->actor?->email ?? '',
                    $log->action,
                    $log->description ?: AuditLogService::describe($log),
                    $log->ip,
                    $log->subject_type,
                    $log->subject_id,
                ]);
            }
            fclose($out);
        }, 'audit-logs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
