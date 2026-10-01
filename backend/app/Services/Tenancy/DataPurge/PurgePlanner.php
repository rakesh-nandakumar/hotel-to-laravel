<?php

namespace App\Services\Tenancy\DataPurge;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Computes, without changing anything, exactly which rows a selection deletes.
 *
 * Starting from the rows the operator selected, it repeatedly follows the
 * purge graph until nothing new is found: dependents are pulled in, owning
 * documents are pulled in whole, and rows that must merely be unlinked are
 * recorded. Every row it touches is checked to belong to the target tenant —
 * one foreign row anywhere aborts the whole plan.
 */
final class PurgePlanner
{
    private const CHUNK = 1000;

    /** @var array<string, array<int, true>> */
    private array $ids = [];

    /** @var array<string, int> */
    private array $seeded = [];

    /** @var array<string, list<int>> */
    private array $pending = [];

    /** @var array<string, array<string, int>> table => "kind:fromTable" => rows */
    private array $reasons = [];

    public function __construct(private readonly PurgeGraph $graph) {}

    /**
     * @param  list<array{key: string, filters: array<string, mixed>}>  $selections  from {@see PurgeCatalog::normalize()}
     */
    public function plan(int $tenantId, array $selections): PurgePlan
    {
        $violations = $this->graph->violations();

        if ($violations !== []) {
            throw new PurgeAbortedException(
                'The purge engine refuses to run: these foreign keys would change tables it must not touch — '.implode(', ', $violations).'.',
            );
        }

        $this->ids = $this->seeded = $this->pending = $this->reasons = [];

        $categories = [];
        $zeroStock = false;

        foreach ($selections as $selection) {
            $category = config("tenancy-purge.categories.{$selection['key']}");
            $roots = [];

            foreach ($category['roots'] as $table) {
                $this->assertScope($table);

                $rootIds = $this->seedQuery($table, $category, $selection['filters'], $tenantId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                $roots[$table] = count($rootIds);
                $this->seed($table, $rootIds);
            }

            $categories[$selection['key']] = [
                'label' => $category['label'],
                'module' => $category['module'],
                'master' => (bool) ($category['master'] ?? false),
                'roots' => $roots,
                'total' => array_sum($roots),
            ];

            if (in_array('zero_stock', $category['reconcile'] ?? [], true)) {
                $zeroStock = true;
            }
        }

        $this->close($tenantId);

        $nullify = $this->collectNullifications($tenantId);
        $ids = $this->sortedIds();

        return new PurgePlan(
            tenantId: $tenantId,
            selections: $selections,
            categories: $categories,
            ids: $ids,
            seeded: $this->seeded,
            reasons: $this->reasonList(),
            nullify: $nullify,
            warnings: $this->warnings($ids),
            zeroStock: $zeroStock,
            fingerprint: $this->fingerprint($ids, $nullify, $zeroStock),
        );
    }

    /**
     * Everything that hangs off the given rows under the same rules as a purge.
     * A restore uses it to undo a document it could only restore in part.
     *
     * @param  array<string, list<int>>  $seeds  table => ids
     * @return array<string, list<int>> table => ids, including the seeds
     */
    public function closure(int $tenantId, array $seeds): array
    {
        $violations = $this->graph->violations();

        if ($violations !== []) {
            throw new PurgeAbortedException('The purge engine refuses to run: '.implode(', ', $violations).'.');
        }

        $this->ids = $this->seeded = $this->pending = $this->reasons = [];

        foreach ($seeds as $table => $ids) {
            $this->assertScope($table);
            $this->seed($table, array_map('intval', $ids));
        }

        $this->close($tenantId);

        return $this->sortedIds();
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<string, mixed>  $filters
     */
    private function seedQuery(string $table, array $category, array $filters, int $tenantId): Builder
    {
        $query = DB::table($table)->where('tenant_id', $tenantId);

        foreach ($category['filters'] as $spec) {
            $value = $filters[$spec['key']] ?? null;

            if ($value === null) {
                continue;
            }

            $column = is_array($spec['column']) ? ($spec['column'][$table] ?? null) : $spec['column'];

            if ($column === null) {
                throw new LogicException("Filter \"{$spec['key']}\" of a purge category has no column for {$table}.");
            }

            match ($spec['type']) {
                'lookup' => $query->whereIn($column, $value),
                'month' => $query
                    ->when($value['from'], fn (Builder $q, string $from) => $q->where($column, '>=', $from))
                    ->when($value['to'], fn (Builder $q, string $to) => $q->where($column, '<=', $to)),
                default => $query
                    ->when($value['from'], fn (Builder $q, string $from) => $q->whereDate($column, '>=', $from))
                    ->when($value['to'], fn (Builder $q, string $to) => $q->whereDate($column, '<=', $to)),
            };
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids
     */
    private function seed(string $table, array $ids): void
    {
        $new = array_values(array_filter($ids, fn (int $id): bool => ! isset($this->ids[$table][$id])));

        foreach ($new as $id) {
            $this->ids[$table][$id] = true;
        }

        if ($new !== []) {
            $this->pending[$table] = array_merge($this->pending[$table] ?? [], $new);
            $this->seeded[$table] = ($this->seeded[$table] ?? 0) + count($new);
        }
    }

    /**
     * Follows the graph to a fixed point.
     */
    private function close(int $tenantId): void
    {
        while ($this->pending !== []) {
            $table = array_key_first($this->pending);
            $batch = $this->pending[$table];
            unset($this->pending[$table]);

            foreach (array_chunk($batch, self::CHUNK) as $chunk) {
                foreach ($this->graph->childrenOf($table) as $edge) {
                    if ($edge['policy'] !== PurgeGraph::CASCADE) {
                        continue;
                    }

                    $childIds = DB::table($edge['child'])->whereIn($edge['column'], $chunk)->pluck('id')->all();
                    $this->claim($edge['child'], $childIds, 'cascade', $table, $tenantId);
                }

                foreach ($this->graph->looseEdgesFrom($table) as $loose) {
                    if ($loose['policy'] !== PurgeGraph::CASCADE) {
                        continue;
                    }

                    $childIds = DB::table($loose['child'])
                        ->where($loose['type_column'], $loose['type_value'])
                        ->whereIn($loose['id_column'], $chunk)
                        ->pluck('id')
                        ->all();
                    $this->claim($loose['child'], $childIds, 'cascade', $table, $tenantId);
                }

                foreach ($this->graph->ownersOf($table) as $owner) {
                    $parentIds = DB::table($table)
                        ->whereIn('id', $chunk)
                        ->whereNotNull($owner['column'])
                        ->distinct()
                        ->pluck($owner['column'])
                        ->all();
                    $this->claim($owner['parent'], $parentIds, 'owner', $table, $tenantId);
                }
            }
        }
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function claim(string $table, array $ids, string $kind, string $from, int $tenantId): void
    {
        if ($ids === []) {
            return;
        }

        $this->assertScope($table);

        $new = array_values(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => ! isset($this->ids[$table][$id]),
        ));

        if ($new === []) {
            return;
        }

        foreach (array_chunk($new, self::CHUNK) as $chunk) {
            $foreign = DB::table($table)
                ->whereIn('id', $chunk)
                ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', '!=', $tenantId))
                ->count();

            if ($foreign > 0) {
                throw $this->foreignRows($table, $foreign);
            }
        }

        foreach ($new as $id) {
            $this->ids[$table][$id] = true;
        }

        $this->pending[$table] = array_merge($this->pending[$table] ?? [], $new);

        $key = "{$kind}:{$from}";
        $this->reasons[$table][$key] = ($this->reasons[$table][$key] ?? 0) + count($new);
    }

    /**
     * Rows that survive the purge but point at a purged row must be unlinked.
     *
     * @return list<array{table: string, column: string, loose: bool, rows: array<int, mixed>}>
     */
    private function collectNullifications(int $tenantId): array
    {
        $entries = [];

        foreach ($this->graph->edges() as $edge) {
            if ($edge['policy'] !== PurgeGraph::NULLIFY || empty($this->ids[$edge['parent']])) {
                continue;
            }

            $rows = [];

            foreach (array_chunk(array_keys($this->ids[$edge['parent']]), self::CHUNK) as $chunk) {
                $found = DB::table($edge['child'])
                    ->whereIn($edge['column'], $chunk)
                    ->get(['id', 'tenant_id', $edge['column']]);

                foreach ($found as $row) {
                    $id = (int) $row->id;

                    if (isset($this->ids[$edge['child']][$id])) {
                        continue;
                    }

                    $this->assertOwn($row->tenant_id, $tenantId, $edge['child']);
                    $rows[$id] = (int) $row->{$edge['column']};
                }
            }

            if ($rows !== []) {
                $entries["{$edge['child']}.{$edge['column']}"] = [
                    'table' => $edge['child'],
                    'column' => $edge['column'],
                    'loose' => false,
                    'rows' => $rows,
                ];
            }
        }

        foreach (config('tenancy-purge.loose_edges', []) as $loose) {
            if ($loose['policy'] !== PurgeGraph::NULLIFY) {
                continue;
            }

            foreach ($loose['targets'] as $typeValue => $parent) {
                if (empty($this->ids[$parent])) {
                    continue;
                }

                foreach (array_chunk(array_keys($this->ids[$parent]), self::CHUNK) as $chunk) {
                    $found = DB::table($loose['child'])
                        ->where($loose['type_column'], (string) $typeValue)
                        ->whereIn($loose['id_column'], $chunk)
                        ->get(['id', 'tenant_id', $loose['type_column'], $loose['id_column']]);

                    foreach ($found as $row) {
                        $id = (int) $row->id;

                        if (isset($this->ids[$loose['child']][$id])) {
                            continue;
                        }

                        $this->assertOwn($row->tenant_id, $tenantId, $loose['child']);

                        foreach ([$loose['type_column'] => $row->{$loose['type_column']}, $loose['id_column'] => (int) $row->{$loose['id_column']}] as $column => $old) {
                            $entries["{$loose['child']}.{$column}"] ??= [
                                'table' => $loose['child'],
                                'column' => $column,
                                'loose' => true,
                                'rows' => [],
                            ];
                            $entries["{$loose['child']}.{$column}"]['rows'][$id] = $old;
                        }
                    }
                }
            }
        }

        return array_values($entries);
    }

    /**
     * @param  array<string, list<int>>  $ids
     * @return list<string>
     */
    private function warnings(array $ids): array
    {
        $warnings = [];

        foreach (config('tenancy-purge.detached_warnings', []) as $rule) {
            $rows = $ids[$rule['table']] ?? [];

            if ($rows === []) {
                continue;
            }

            $keptParents = [];
            $affectedRows = 0;

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $parents = DB::table($rule['table'])->whereIn('id', $chunk)->whereNotNull($rule['column'])->pluck($rule['column']);

                foreach ($parents as $parent) {
                    if (! isset($this->ids[$rule['parent']][(int) $parent])) {
                        $keptParents[(int) $parent] = true;
                        $affectedRows++;
                    }
                }
            }

            if ($affectedRows > 0) {
                $warnings[] = strtr($rule['message'], [
                    ':rows' => number_format($affectedRows),
                    ':parents' => number_format(count($keptParents)),
                ]);
            }
        }

        foreach ($this->liveActivityWarnings($ids) as $warning) {
            $warnings[] = $warning;
        }

        return $warnings;
    }

    /**
     * Warn when the purge removes records that are in use right now.
     *
     * @param  array<string, list<int>>  $ids
     * @return list<string>
     */
    private function liveActivityWarnings(array $ids): array
    {
        $warnings = [];

        if (! empty($ids['till_sessions'])) {
            $open = 0;
            foreach (array_chunk($ids['till_sessions'], self::CHUNK) as $chunk) {
                $open += DB::table('till_sessions')->whereIn('id', $chunk)->whereNull('closed_at')->count();
            }

            if ($open > 0) {
                $warnings[] = "{$open} open till session(s) will be deleted — staff must open a new session before taking cash.";
            }
        }

        foreach ([
            ['reservations', 'reservation_status_id', 'reservation_status', ['checked_in'], 'guest(s) are currently checked in and lose their stay record.'],
            ['orders', 'order_status_id', 'order_status', ['open', 'parked'], 'open or parked order(s) will be deleted.'],
        ] as [$table, $column, $type, $codes, $message]) {
            if (empty($ids[$table])) {
                continue;
            }

            $statusIds = DB::table('lookups')->where('type', $type)->whereIn('code', $codes)->pluck('id')->all();

            if ($statusIds === []) {
                continue;
            }

            $count = 0;
            foreach (array_chunk($ids[$table], self::CHUNK) as $chunk) {
                $count += DB::table($table)->whereIn('id', $chunk)->whereIn($column, $statusIds)->count();
            }

            if ($count > 0) {
                $warnings[] = "{$count} {$message}";
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, list<int>>
     */
    private function sortedIds(): array
    {
        $sorted = [];

        foreach ($this->ids as $table => $set) {
            $list = array_keys($set);
            sort($list);
            $sorted[$table] = $list;
        }

        ksort($sorted);

        return $sorted;
    }

    /**
     * @return array<string, list<array{kind: string, table: string, count: int}>>
     */
    private function reasonList(): array
    {
        $list = [];

        foreach ($this->reasons as $table => $reasons) {
            foreach ($reasons as $key => $count) {
                [$kind, $from] = explode(':', $key, 2);
                $list[$table][] = ['kind' => $kind, 'table' => $from, 'count' => $count];
            }
        }

        return $list;
    }

    /**
     * @param  array<string, list<int>>  $ids
     * @param  list<array{table: string, column: string, loose: bool, rows: array<int, mixed>}>  $nullify
     */
    private function fingerprint(array $ids, array $nullify, bool $zeroStock): string
    {
        $hash = hash_init('sha256');

        foreach ($ids as $table => $list) {
            hash_update($hash, $table.':'.implode(',', $list).';');
        }

        foreach ($nullify as $entry) {
            hash_update($hash, "n:{$entry['table']}.{$entry['column']}:".implode(',', array_keys($entry['rows'])).';');
        }

        hash_update($hash, 'z:'.(int) $zeroStock);

        return hash_final($hash);
    }

    private function assertScope(string $table): void
    {
        if (! $this->graph->isScope($table)) {
            throw new PurgeAbortedException(sprintf(
                '"%s" is protected data and can never be purged, but this selection would reach it. Nothing was changed.',
                $this->graph->label($table),
            ));
        }
    }

    private function assertOwn(mixed $rowTenantId, int $tenantId, string $table): void
    {
        if ($rowTenantId === null || (int) $rowTenantId !== $tenantId) {
            throw $this->foreignRows($table, 1);
        }
    }

    private function foreignRows(string $table, int $count): PurgeAbortedException
    {
        return new PurgeAbortedException(sprintf(
            '%d "%s" row(s) linked to this tenant\'s data belong to another tenant (or to no tenant). The purge was refused so that no other tenant is affected. Nothing was changed.',
            $count,
            $this->graph->label($table),
        ));
    }
}
