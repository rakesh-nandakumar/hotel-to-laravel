<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\DataPurgeAudit;
use App\Services\Tenancy\DataPurge\DataPurgeService;
use App\Services\Tenancy\DataPurge\DataRestoreService;
use App\Services\Tenancy\DataPurge\PurgeAbortedException;
use App\Services\Tenancy\DataPurge\PurgeBackup;
use App\Services\Tenancy\DataPurge\PurgeCatalog;
use App\Services\Tenancy\DataPurge\PurgeConflictException;
use App\Services\Tenancy\DataPurge\PurgeGraph;
use App\Services\Tenancy\DataPurge\PurgePlanner;
use App\Services\Tenancy\DataPurge\PurgeToken;
use App\Services\Tenancy\DataPurge\TenantStateReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Data purge & restore (Master Control → tenant → Data): bulk-delete whole
 * categories of one tenant's data behind a preview, a password, a server-enforced
 * delay and a mandatory restorable backup.
 *
 * See App\Services\Tenancy\DataPurge\* for the engine and
 * config/tenancy-purge.php for the catalog.
 */
class TenantDataPurgeController extends Controller
{
    public function __construct(
        private readonly PurgeCatalog $categories,
        private readonly PurgePlanner $planner,
        private readonly PurgeGraph $graph,
        private readonly DataPurgeService $purger,
        private readonly DataRestoreService $restorer,
        private readonly TenantStateReconciler $reconciler,
    ) {}

    /**
     * Every purgeable category with its live row count and filter options.
     */
    public function catalog(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'modules' => $this->categories->describe($tenant->id),
            'retention_days' => (int) config('tenancy-purge.retention_days'),
            'min_confirm_seconds' => (int) config('tenancy-purge.min_confirm_seconds'),
        ]);
    }

    /**
     * Dry run: exactly what the selection would delete, plus the token that a
     * confirmed purge must present.
     */
    public function preview(Request $request, Tenant $tenant): JsonResponse
    {
        $selections = $this->categories->normalize($request->input('selections'));

        try {
            $plan = $this->planner->plan($tenant->id, $selections);
        } catch (PurgeAbortedException $e) {
            abort(422, $e->getMessage());
        }

        abort_if($plan->isEmpty(), 422, 'Nothing matches this selection, so there is no data to delete.');

        return response()->json([
            'plan' => [...$plan->toSummary($this->graph), 'reconcile' => $this->reconciler->preview($plan)],
            'token' => PurgeToken::issue(PurgeToken::PURGE, [
                'tenant' => $tenant->id,
                'admin' => $this->admin($request)->id,
                'selections' => $selections,
                'fingerprint' => $plan->fingerprint,
            ]),
            'min_confirm_seconds' => (int) config('tenancy-purge.min_confirm_seconds'),
            'retention_days' => (int) config('tenancy-purge.retention_days'),
        ]);
    }

    public function purge(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'current_password:central'],
            'continue_numbering' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = $this->admin($request);
        $claims = PurgeToken::read($data['token'], PurgeToken::PURGE);
        $this->assertIssuedFor($claims, $tenant, $admin);

        try {
            $purge = $this->purger->execute(
                $tenant,
                $claims['selections'],
                $claims['fingerprint'],
                $admin,
                (bool) ($data['continue_numbering'] ?? false),
                $data['note'] ?? null,
            );
        } catch (PurgeConflictException $e) {
            abort(409, $e->getMessage());
        } catch (PurgeAbortedException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'purge' => $this->present($purge->load('centralAdmin:id,name,email')),
            'message' => sprintf('Deleted %s record(s). A backup is kept until %s.', number_format($purge->total_rows), $purge->expires_at->toFormattedDateString()),
        ], 201);
    }

    /**
     * Purge history, newest first.
     */
    public function purges(Tenant $tenant): JsonResponse
    {
        $purges = TenantDataPurge::query()
            ->where('tenant_id', $tenant->id)
            ->with(['centralAdmin:id,name,email', 'restoredBy:id,name,email'])
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['purges' => $purges->map(fn (TenantDataPurge $p): array => $this->present($p))->all()]);
    }

    public function download(Request $request, Tenant $tenant, TenantDataPurge $purge): BinaryFileResponse
    {
        $this->assertOwned($tenant, $purge);
        abort_unless($purge->backup_path !== null && PurgeBackup::exists($purge->backup_path), 404, 'This backup is no longer available.');

        DataPurgeAudit::record(
            $tenant,
            $this->admin($request),
            'tenant.data_backup_downloaded',
            sprintf('Platform operator %s downloaded the backup of purge %s.', $this->admin($request)->email, $purge->uuid),
            ['purge' => $purge->uuid],
        );

        return response()->download(
            PurgeBackup::absolutePath($purge->backup_path),
            "purge-{$tenant->slug}-{$purge->uuid}.ndjson.gz",
        );
    }

    /**
     * Dry-runs a restore: what would come back, what would be renumbered, what
     * could not be restored and why. Changes nothing.
     */
    public function restorePreview(Request $request, Tenant $tenant, TenantDataPurge $purge): JsonResponse
    {
        $this->assertOwned($tenant, $purge);

        try {
            $report = $this->restorer->check($purge);
        } catch (PurgeConflictException $e) {
            abort(409, $e->getMessage());
        } catch (PurgeAbortedException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'report' => $report,
            'token' => PurgeToken::issue(PurgeToken::RESTORE, [
                'tenant' => $tenant->id,
                'admin' => $this->admin($request)->id,
                'purge' => $purge->id,
            ]),
            'min_confirm_seconds' => (int) config('tenancy-purge.min_confirm_seconds'),
        ]);
    }

    public function restore(Request $request, Tenant $tenant, TenantDataPurge $purge): JsonResponse
    {
        $this->assertOwned($tenant, $purge);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'current_password:central'],
        ]);

        $admin = $this->admin($request);
        $claims = PurgeToken::read($data['token'], PurgeToken::RESTORE);
        $this->assertIssuedFor($claims, $tenant, $admin);

        if ((int) ($claims['purge'] ?? 0) !== $purge->id) {
            throw ValidationException::withMessages(['token' => 'This confirmation was issued for a different purge. Preview again.']);
        }

        try {
            $report = $this->restorer->restore($purge, $admin);
        } catch (PurgeConflictException $e) {
            abort(409, $e->getMessage());
        } catch (PurgeAbortedException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'report' => $report,
            'purge' => $this->present($purge->refresh()->load(['centralAdmin:id,name,email', 'restoredBy:id,name,email'])),
            'message' => sprintf('Restored %s record(s).', number_format($report['total_restored'])),
        ]);
    }

    private function admin(Request $request): CentralAdmin
    {
        /** @var CentralAdmin */
        return $request->user('central');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertIssuedFor(array $claims, Tenant $tenant, CentralAdmin $admin): void
    {
        if ((int) ($claims['tenant'] ?? 0) !== $tenant->id || (int) ($claims['admin'] ?? 0) !== $admin->id) {
            throw ValidationException::withMessages(['token' => 'This confirmation was issued for a different tenant or operator. Preview again.']);
        }
    }

    private function assertOwned(Tenant $tenant, TenantDataPurge $purge): void
    {
        abort_unless($purge->tenant_id === $tenant->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TenantDataPurge $purge): array
    {
        return [
            'id' => $purge->id,
            'uuid' => $purge->uuid,
            'status' => $purge->status,
            'created_at' => $purge->created_at,
            'total_rows' => $purge->total_rows,
            'summary' => $purge->summary,
            'note' => $purge->note,
            'continue_numbering' => $purge->continue_numbering,
            'operator' => $purge->centralAdmin?->only(['id', 'name', 'email']),
            'backup_bytes' => $purge->backup_bytes,
            'expires_at' => $purge->expires_at,
            'restorable' => $purge->isRestorable(),
            'restored_at' => $purge->restored_at,
            'restored_by' => $purge->restoredBy?->only(['id', 'name', 'email']),
            'restore_summary' => $purge->restore_summary,
        ];
    }
}
