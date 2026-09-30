<?php

namespace App\Services\Tenancy\DataPurge;

use App\Models\CentralAdmin;
use App\Models\TenantDataPurge;
use App\Services\DocumentNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puts a purge's deleted rows back, without ever failing on a database error.
 *
 * The tenant has usually carried on working since the purge, so the restore
 * cannot simply re-insert. Every row is reconciled with the current state:
 *
 *  - a document number that was reused since is renumbered to the next free one;
 *  - a link to a row that no longer exists is cleared when the link is optional;
 *  - a row that cannot exist without a missing parent is skipped, and so is
 *    everything that hangs off it;
 *  - a row whose id or a unique value is taken is skipped.
 *
 * Everything skipped, renumbered or unlinked is reported. {@see check()} runs
 * the exact same logic and then rolls back, so the preview cannot disagree
 * with the real restore.
 */
final class DataRestoreService
{
    private const SAMPLE_LIMIT = 100;

    private const INSERT_PARAMS = 20000;

    /** @var list<string> */
    private array $order = [];

    /** @var array<string, list<string>> */
    private array $backupColumns = [];

    /** @var array<string, array<int, true>> table => ids known to exist */
    private array $known = [];

    /** @var list<array{table: string, id: int, column: string, value: int}> */
    private array $deferred = [];

    /** @var array<string, int> */
    private array $allocated = [];

    /** @var array<string, mixed> */
    private array $report = [];

    public function __construct(
        private readonly PurgeGraph $graph,
        private readonly TenantStateReconciler $reconciler,
    ) {}

    /**
     * Dry run: reports exactly what a restore would do, then rolls it back.
     *
     * @return array<string, mixed>
     */
    public function check(TenantDataPurge $purge): array
    {
        return $this->run($purge, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(TenantDataPurge $purge, CentralAdmin $admin): array
    {
        $report = $this->run($purge, $admin);

        Cache::forget('pos.menu_categories');

        DataPurgeAudit::record(
            $purge->tenant()->withTrashed()->firstOrFail(),
            $admin,
            'tenant.data_restored',
            sprintf(
                'Platform operator %s restored %s record(s) from purge %s (%s skipped, %s renumbered).',
                $admin->email,
                number_format($report['total_restored']),
                $purge->uuid,
                number_format($report['skipped']['total']),
                number_format($report['renumbered']['total']),
            ),
            ['purge' => $purge->uuid, 'restored' => $report['total_restored'], 'skipped' => $report['skipped']['total']],
        );

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(TenantDataPurge $purge, ?CentralAdmin $admin): array
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $this->assertRestorable($purge);

        $lock = Cache::lock("tenant-data-operation:{$purge->tenant_id}", 3600);

        if (! $lock->get()) {
            throw new PurgeConflictException('Another purge or restore is already running for this tenant. Try again in a moment.');
        }

        try {
            DB::beginTransaction();

            try {
                $report = $this->replay($purge);

                if ($admin !== null) {
                    $purge->forceFill([
                        'status' => TenantDataPurge::STATUS_RESTORED,
                        'restored_at' => now(),
                        'restored_by' => $admin->id,
                        'restore_summary' => $report,
                    ])->save();
                }
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            $admin === null ? DB::rollBack() : DB::commit();
        } finally {
            $lock->release();
        }

        return ['dry_run' => $admin === null, ...$report];
    }

    private function assertRestorable(TenantDataPurge $purge): void
    {
        if ($purge->status === TenantDataPurge::STATUS_RESTORED) {
            throw new PurgeAbortedException('This purge was already restored.');
        }

        if ($purge->status === TenantDataPurge::STATUS_EXPIRED || ($purge->expires_at !== null && $purge->expires_at->isPast())) {
            throw new PurgeAbortedException('This backup has expired and was removed.');
        }

        if ($purge->backup_path === null || ! PurgeBackup::exists($purge->backup_path)) {
            throw new PurgeAbortedException('The backup file for this purge is missing.');
        }

        if (! hash_equals((string) $purge->backup_sha256, PurgeBackup::checksum($purge->backup_path))) {
            throw new PurgeAbortedException('The backup file failed its integrity check and cannot be restored.');
        }

        if ($purge->tenant()->first() === null) {
            throw new PurgeAbortedException('The tenant this purge belongs to no longer exists.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function replay(TenantDataPurge $purge): array
    {
        $tenantId = (int) $purge->tenant_id;

        $this->order = [];
        $this->backupColumns = [];
        $this->known = [];
        $this->deferred = [];
        $this->allocated = [];
        $this->report = [
            'restored' => [],
            'total_restored' => 0,
            'skipped' => ['total' => 0, 'by_table' => [], 'samples' => []],
            'renumbered' => ['total' => 0, 'samples' => []],
            'unlinked' => [],
            'relinked' => 0,
            'state_repairs_reverted' => 0,
            'state_repairs_left_alone' => 0,
            'dropped_columns' => [],
        ];

        $nullify = [];
        $changes = [];
        $sawHeader = false;
        $sawFooter = false;

        foreach (PurgeBackup::read($purge->backup_path) as $record) {
            switch ($record['type'] ?? null) {
                case 'header':
                    if (($record['version'] ?? null) !== PurgeBackup::VERSION || (int) ($record['tenant_id'] ?? 0) !== $tenantId) {
                        throw new PurgeAbortedException('The backup does not belong to this tenant or was made by an incompatible version.');
                    }
                    $this->order = $record['order'];
                    $this->backupColumns = $record['columns'];
                    $sawHeader = true;
                    break;

                case 'rows':
                    if (! $sawHeader) {
                        throw new PurgeAbortedException('The backup file is corrupted (no header).');
                    }
                    $this->restoreRows($tenantId, $record['table'], $record['rows']);
                    break;

                case 'nullify':
                    $nullify[] = $record;
                    break;

                case 'reconcile':
                    array_push($changes, ...$record['changes']);
                    break;

                case 'footer':
                    $sawFooter = true;
                    break;
            }
        }

        if (! $sawHeader || ! $sawFooter) {
            throw new PurgeAbortedException('The backup file is incomplete and cannot be restored.');
        }

        $this->applyDeferred();
        $this->reapplyNullified($tenantId, $nullify);

        $reverted = $this->reconciler->revert($tenantId, $changes);
        $this->report['state_repairs_reverted'] = $reverted['reverted'];
        $this->report['state_repairs_left_alone'] = $reverted['left_alone'];

        return $this->report;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function restoreRows(int $tenantId, string $table, array $rows): void
    {
        if (! $this->graph->isScope($table)) {
            throw new PurgeAbortedException("The backup contains \"{$table}\", which a restore may never write to.");
        }

        $current = $this->graph->columns($table);
        $insertable = array_values(array_intersect($this->backupColumns[$table] ?? array_keys($rows[0] ?? []), array_keys($current)));
        $dropped = array_values(array_diff($this->backupColumns[$table] ?? [], array_keys($current)));

        if ($dropped !== []) {
            $this->report['dropped_columns'][$table] = $dropped;
        }

        $rows = array_map(function (array $row) use ($insertable, $tenantId): array {
            $row = Arr::only($row, $insertable);
            $row['tenant_id'] = $tenantId;

            return $row;
        }, $rows);

        $rows = $this->dropTakenIds($table, $rows);
        $rows = $this->renumber($tenantId, $table, $rows);
        $rows = $this->resolveLinks($table, $rows);

        if ($rows === []) {
            return;
        }

        $this->insert($table, $rows, count($insertable));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dropTakenIds(string $table, array $rows): array
    {
        $taken = DB::table($table)->whereIn('id', array_column($rows, 'id'))->pluck('id')->map(fn (mixed $id): int => (int) $id)->flip();

        return array_values(array_filter($rows, function (array $row) use ($table, $taken): bool {
            if ($taken->has((int) $row['id'])) {
                $this->skip($table, (int) $row['id'], 'its id was taken by a newer record');

                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function renumber(int $tenantId, string $table, array $rows): array
    {
        foreach (config('tenancy-purge.document_numbers', []) as $series) {
            if ($series['table'] !== $table) {
                continue;
            }

            $column = $series['column'];
            $values = array_values(array_filter(array_column($rows, $column), fn (mixed $v): bool => $v !== null));

            if ($values === []) {
                continue;
            }

            $taken = DB::table($table)->where('tenant_id', $tenantId)->whereIn($column, $values)->pluck($column)
                ->map(fn (mixed $v): string => (string) $v)->flip()->all();

            // Numbers already claimed by this very batch must not be handed out again.
            $inBatch = array_flip(array_map('strval', $values));

            foreach ($rows as $i => $row) {
                $old = $row[$column] ?? null;

                if ($old === null || ! isset($taken[(string) $old])) {
                    continue;
                }

                do {
                    $new = $this->nextNumber($tenantId, $table, $series, (string) $old);
                } while ($new !== null && (isset($inBatch[(string) $new]) || isset($taken[(string) $new])));

                if ($new === null) {
                    $this->skip($table, (int) $row['id'], "its number \"{$old}\" is in use and cannot be renumbered");
                    unset($rows[$i]);

                    continue;
                }

                $rows[$i][$column] = $new;
                $inBatch[(string) $new] = true;
                $this->report['renumbered']['total']++;

                if (count($this->report['renumbered']['samples']) < self::SAMPLE_LIMIT) {
                    $this->report['renumbered']['samples'][] = ['table' => $table, 'column' => $column, 'from' => (string) $old, 'to' => (string) $new];
                }
            }
        }

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function nextNumber(int $tenantId, string $table, array $series, string $old): string|int|null
    {
        $column = $series['column'];

        if (($series['kind'] ?? 'prefixed') === 'numeric') {
            $key = "{$table}.{$column}";
            $this->allocated[$key] ??= (int) DB::table($table)->where('tenant_id', $tenantId)->max($column);

            return ++$this->allocated[$key];
        }

        $parsed = DocumentNumberService::parse($old);

        if ($parsed === null) {
            return null;
        }

        $key = "{$table}.{$column}.{$parsed['prefix']}";

        if (! isset($this->allocated[$key])) {
            $highest = (int) DB::table('tenant_document_number_floors')
                ->where('tenant_id', $tenantId)
                ->where('prefix', $parsed['prefix'])
                ->value('last_number');

            foreach (DB::table($table)->where('tenant_id', $tenantId)->where($column, 'like', $parsed['prefix'].'%')->pluck($column) as $code) {
                $highest = max($highest, DocumentNumberService::parse((string) $code)['number'] ?? 0);
            }

            $this->allocated[$key] = $highest;
        }

        return $parsed['prefix'].str_pad((string) ++$this->allocated[$key], $parsed['width'], '0', STR_PAD_LEFT);
    }

    /**
     * Applies each foreign key's rule to every row: keep, unlink, defer or skip.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function resolveLinks(string $table, array $rows): array
    {
        $position = array_flip($this->order);
        $existing = [];

        foreach ($this->graph->foreignKeys($table) as $fk) {
            $deferrable = $fk['nullable']
                && $this->graph->policyFor($table, $fk['column']) === PurgeGraph::NULLIFY
                && ($fk['parent'] === $table || (isset($position[$fk['parent']]) && $position[$fk['parent']] > $position[$table]));

            if ($deferrable) {
                continue;
            }

            $values = array_values(array_filter(array_column($rows, $fk['column']), fn (mixed $v): bool => $v !== null));
            $existing[$fk['column']] = $values === [] ? [] : $this->existing($fk['parent'], array_map('intval', $values));
        }

        $kept = [];

        foreach ($rows as $row) {
            $skipReason = null;

            foreach ($this->graph->foreignKeys($table) as $fk) {
                $column = $fk['column'];
                $value = $row[$column] ?? null;

                if ($value === null || ! array_key_exists($column, $row)) {
                    continue;
                }

                if (! isset($existing[$column])) {
                    $this->deferred[] = ['table' => $table, 'id' => (int) $row['id'], 'column' => $column, 'value' => (int) $value];
                    $row[$column] = null;

                    continue;
                }

                if (isset($existing[$column][(int) $value])) {
                    continue;
                }

                $ownership = $this->graph->policyFor($table, $column) === PurgeGraph::CASCADE;

                if ($ownership || ! $fk['nullable']) {
                    $skipReason = sprintf('the %s it belongs to (#%d) no longer exists', $this->graph->label($fk['parent']), $value);
                    break;
                }

                $row[$column] = null;
                $this->report['unlinked']["{$table}.{$column}"] = ($this->report['unlinked']["{$table}.{$column}"] ?? 0) + 1;
            }

            if ($skipReason !== null) {
                $this->skip($table, (int) $row['id'], $skipReason);

                continue;
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows, int $columnCount): void
    {
        $perStatement = max(1, intdiv(self::INSERT_PARAMS, max(1, $columnCount)));

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            try {
                DB::table($table)->insert($chunk);
                $this->inserted($table, $chunk);
            } catch (QueryException) {
                // A single bad row must not sink the rest: fall back to row by row.
                foreach ($chunk as $row) {
                    $this->insertOne($table, $row);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertOne(string $table, array $row): void
    {
        try {
            DB::table($table)->insert($row);
            $this->inserted($table, [$row]);
        } catch (QueryException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'UNIQUE constraint failed') || str_contains($message, 'Duplicate entry') || str_contains($message, 'Integrity constraint violation: 1062')) {
                $this->skip($table, (int) $row['id'], 'a record with the same unique value already exists');

                return;
            }

            if (str_contains($message, 'FOREIGN KEY constraint failed') || str_contains($message, '1452')) {
                $this->skip($table, (int) $row['id'], 'a record it depends on no longer exists');

                return;
            }

            throw new PurgeAbortedException("Restoring {$this->graph->label($table)} failed: ".$e->getPrevious()?->getMessage().' Nothing was restored.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function inserted(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $this->known[$table][(int) $row['id']] = true;
        }

        $this->report['restored'][$table] = ($this->report['restored'][$table] ?? 0) + count($rows);
        $this->report['total_restored'] += count($rows);
    }

    private function skip(string $table, int $id, string $reason): void
    {
        $this->report['skipped']['total']++;
        $this->report['skipped']['by_table'][$table] = ($this->report['skipped']['by_table'][$table] ?? 0) + 1;

        if (count($this->report['skipped']['samples']) < self::SAMPLE_LIMIT) {
            $this->report['skipped']['samples'][] = ['table' => $table, 'id' => $id, 'reason' => "Skipped {$this->graph->label($table)} #{$id}: {$reason}."];
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function existing(string $table, array $ids): array
    {
        $found = [];
        $need = [];

        foreach (array_unique($ids) as $id) {
            isset($this->known[$table][$id]) ? $found[$id] = true : $need[] = $id;
        }

        foreach (array_chunk($need, 1000) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->pluck('id') as $id) {
                $found[(int) $id] = true;
                $this->known[$table][(int) $id] = true;
            }
        }

        return $found;
    }

    /**
     * Links that pointed forward (or at the same table) while rows were still
     * being inserted are set now that every parent has had its chance.
     */
    private function applyDeferred(): void
    {
        foreach ($this->deferred as $link) {
            $parent = collect($this->graph->foreignKeys($link['table']))->firstWhere('column', $link['column'])['parent'] ?? null;

            if ($parent === null) {
                continue;
            }

            $present = $this->existing($parent, [$link['value']]);

            if (isset($present[$link['value']])) {
                DB::table($link['table'])->where('id', $link['id'])->update([$link['column'] => $link['value']]);
            }
        }
    }

    /**
     * Rows that were only unlinked by the purge get their links back — where
     * the row still exists, the link is still empty, and the target is there.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function reapplyNullified(int $tenantId, array $records): void
    {
        foreach ($records as $record) {
            $table = $record['table'];
            $column = $record['column'];
            $parent = $record['loose'] ? null : (collect($this->graph->foreignKeys($table))->firstWhere('column', $column)['parent'] ?? null);

            foreach (array_chunk(array_keys($record['rows']), 500) as $chunk) {
                $live = DB::table($table)->where('tenant_id', $tenantId)->whereIn('id', $chunk)->whereNull($column)->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)->all();

                $byValue = [];
                foreach ($live as $id) {
                    $byValue[$record['rows'][$id]][] = $id;
                }

                $present = $parent === null ? null : $this->existing($parent, array_map('intval', array_keys($byValue)));

                foreach ($byValue as $value => $ids) {
                    if ($present !== null && ! isset($present[(int) $value])) {
                        continue;
                    }

                    $restored = DB::table($table)->whereIn('id', $ids)->update([$column => $value]);

                    if (! $record['loose'] || ! str_ends_with($column, '_type')) {
                        $this->report['relinked'] += $restored;
                    }
                }
            }
        }
    }
}
