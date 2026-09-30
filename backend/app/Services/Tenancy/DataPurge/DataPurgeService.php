<?php

namespace App\Services\Tenancy\DataPurge;

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes a previewed purge for one tenant, all-or-nothing:
 *
 *   re-plan (must match the preview) → write the backup → unlink kept rows →
 *   delete children before parents (every table's count verified) →
 *   repair derived state → settle document numbering → record history.
 *
 * It all happens in one DB transaction. If anything fails the database is
 * rolled back and the half-written backup file is removed, so a failed purge
 * leaves the tenant exactly as it was.
 */
final class DataPurgeService
{
    private const CHUNK = 500;

    public function __construct(
        private readonly PurgePlanner $planner,
        private readonly PurgeGraph $graph,
        private readonly TenantStateReconciler $reconciler,
        private readonly PurgeNumbering $numbering,
    ) {}

    /**
     * @param  list<array{key: string, filters: array<string, mixed>}>  $selections  normalised
     */
    public function execute(
        Tenant $tenant,
        array $selections,
        string $expectedFingerprint,
        CentralAdmin $admin,
        bool $continueNumbering,
        ?string $note,
    ): TenantDataPurge {
        set_time_limit(0);
        ignore_user_abort(true);

        $lock = Cache::lock("tenant-data-operation:{$tenant->id}", 3600);

        if (! $lock->get()) {
            throw new PurgeConflictException('Another purge or restore is already running for this tenant. Try again in a moment.');
        }

        $backup = new PurgeBackup;

        try {
            $purge = DB::transaction(fn (): TenantDataPurge => $this->run($tenant, $selections, $expectedFingerprint, $admin, $continueNumbering, $note, $backup));
        } catch (QueryException $e) {
            $backup->abort();
            Log::error('Tenant data purge rolled back', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

            if (str_starts_with((string) $e->getCode(), '23')) {
                throw new PurgeConflictException('The tenant\'s data changed while the purge was running, so it was rolled back. Nothing was deleted — preview and try again.');
            }

            throw $e;
        } catch (Throwable $e) {
            $backup->abort();

            throw $e;
        } finally {
            $lock->release();
        }

        Cache::forget('pos.menu_categories');

        DataPurgeAudit::record(
            $tenant,
            $admin,
            'tenant.data_purged',
            sprintf(
                'Platform operator %s purged %s record(s) (%s) from this tenant. A backup is kept until %s.',
                $admin->email,
                number_format($purge->total_rows),
                collect($selections)->pluck('key')->implode(', '),
                $purge->expires_at->toDateString(),
            ),
            [
                'purge' => $purge->uuid,
                'categories' => collect($selections)->pluck('key')->all(),
                'total_rows' => $purge->total_rows,
                'continue_numbering' => $continueNumbering,
            ],
        );

        return $purge;
    }

    /**
     * @param  list<array{key: string, filters: array<string, mixed>}>  $selections
     */
    private function run(
        Tenant $tenant,
        array $selections,
        string $expectedFingerprint,
        CentralAdmin $admin,
        bool $continueNumbering,
        ?string $note,
        PurgeBackup $backup,
    ): TenantDataPurge {
        $plan = $this->planner->plan($tenant->id, $selections);

        if (! hash_equals($expectedFingerprint, $plan->fingerprint)) {
            throw new PurgeConflictException('The tenant\'s data changed since you previewed this purge. Preview again to see the current numbers. Nothing was deleted.');
        }

        if ($plan->isEmpty()) {
            throw new PurgeAbortedException('There is nothing to delete for this selection.');
        }

        $uuid = (string) Str::uuid();
        $path = PurgeBackup::relativePathFor($tenant->id, $uuid);

        $captured = $this->reconciler->capture($plan);
        $highestNumbers = $this->numbering->collect($plan);

        $this->writeBackupRows($backup, $path, $tenant, $plan);

        foreach ($plan->nullify as $entry) {
            $backup->write(['type' => 'nullify', ...$entry]);

            foreach (array_chunk(array_keys($entry['rows']), self::CHUNK) as $chunk) {
                DB::table($entry['table'])->where('tenant_id', $tenant->id)->whereIn('id', $chunk)->update([$entry['column'] => null]);
            }
        }

        $this->deleteRows($tenant, $plan);

        $changes = $this->reconciler->apply($plan, $captured);

        foreach (array_chunk($changes, self::CHUNK) as $chunk) {
            $backup->write(['type' => 'reconcile', 'changes' => $chunk]);
        }

        $this->numbering->apply($tenant->id, $highestNumbers, $continueNumbering);

        $backup->write(['type' => 'footer', 'counts' => $plan->counts()]);
        $file = $backup->finish();

        return TenantDataPurge::create([
            'uuid' => $uuid,
            'tenant_id' => $tenant->id,
            'central_admin_id' => $admin->id,
            'status' => TenantDataPurge::STATUS_COMPLETED,
            'selections' => $plan->selections,
            'summary' => [
                ...$plan->toSummary($this->graph),
                'reconciled' => count($changes),
                'numbering' => $continueNumbering ? 'continue' : 'restart',
            ],
            'fingerprint' => $plan->fingerprint,
            'note' => $note,
            'continue_numbering' => $continueNumbering,
            'total_rows' => $plan->totalRows(),
            'backup_path' => $path,
            'backup_bytes' => $file['bytes'],
            'backup_sha256' => $file['sha256'],
            'expires_at' => now()->addDays((int) config('tenancy-purge.retention_days')),
        ]);
    }

    private function writeBackupRows(PurgeBackup $backup, string $path, Tenant $tenant, PurgePlan $plan): void
    {
        $order = array_values(array_filter($this->graph->insertOrder(), fn (string $table): bool => ! empty($plan->ids[$table])));

        $backup->open($path);
        $backup->write([
            'type' => 'header',
            'version' => PurgeBackup::VERSION,
            'tenant_id' => $tenant->id,
            'created_at' => now()->toIso8601String(),
            'order' => $order,
            'columns' => collect($order)->mapWithKeys(fn (string $table): array => [$table => array_keys($this->graph->columns($table))])->all(),
        ]);

        foreach ($order as $table) {
            foreach (array_chunk($plan->ids[$table], self::CHUNK) as $chunk) {
                $rows = DB::table($table)
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('id', $chunk)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (object $row): array => (array) $row)
                    ->all();

                if (count($rows) !== count($chunk)) {
                    throw new PurgeConflictException("Some {$this->graph->label($table)} changed while the backup was being written. Nothing was deleted — preview and try again.");
                }

                $backup->writeRows($table, $rows);
            }
        }
    }

    private function deleteRows(Tenant $tenant, PurgePlan $plan): void
    {
        foreach ($this->graph->deletionOrder() as $table) {
            foreach (array_chunk($plan->ids[$table] ?? [], self::CHUNK) as $chunk) {
                $deleted = DB::table($table)->where('tenant_id', $tenant->id)->whereIn('id', $chunk)->delete();

                if ($deleted !== count($chunk)) {
                    throw new PurgeConflictException(sprintf(
                        'Expected to delete %d %s but %d were removed. The purge was rolled back and nothing was deleted.',
                        count($chunk),
                        $this->graph->label($table),
                        $deleted,
                    ));
                }
            }
        }
    }
}
