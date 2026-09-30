<?php

namespace App\Services\Tenancy\DataPurge;

/**
 * The exact, computed outcome of a purge selection: which rows go, which rows
 * are unlinked, and what the operator should be warned about. Built by
 * {@see PurgePlanner}; nothing has been changed when one exists.
 */
final class PurgePlan
{
    /**
     * @param  list<array{key: string, filters: array<string, mixed>}>  $selections  normalised selections
     * @param  array<string, array{label: string, module: string, master: bool, roots: array<string, int>, total: int}>  $categories
     * @param  array<string, list<int>>  $ids  table => ids to delete (ascending)
     * @param  array<string, int>  $seeded  table => rows selected directly by a category
     * @param  array<string, list<array{kind: string, table: string, count: int}>>  $reasons  table => why rows were pulled in
     * @param  list<array{table: string, column: string, loose: bool, rows: array<int, mixed>}>  $nullify  rows kept but unlinked
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $selections,
        public readonly array $categories,
        public readonly array $ids,
        public readonly array $seeded,
        public readonly array $reasons,
        public readonly array $nullify,
        public readonly array $warnings,
        public readonly bool $zeroStock,
        public readonly string $fingerprint,
    ) {}

    public function totalRows(): int
    {
        return array_sum(array_map('count', $this->ids));
    }

    public function isEmpty(): bool
    {
        return $this->totalRows() === 0 && ! $this->zeroStock;
    }

    /**
     * @return array<string, int> table => rows to delete
     */
    public function counts(): array
    {
        return array_map('count', $this->ids);
    }

    /**
     * Operator-facing summary (also stored on the purge history row).
     *
     * @return array<string, mixed>
     */
    public function toSummary(PurgeGraph $graph): array
    {
        $categories = [];
        foreach ($this->categories as $key => $category) {
            $categories[] = [
                'key' => $key,
                'label' => $category['label'],
                'module' => $category['module'],
                'master' => $category['master'],
                'count' => $category['total'],
                'roots' => collect($category['roots'])
                    ->map(fn (int $count, string $table): array => ['table' => $table, 'label' => $graph->label($table), 'count' => $count])
                    ->values()
                    ->all(),
            ];
        }

        $also = [];
        foreach ($this->ids as $table => $ids) {
            $extra = count($ids) - ($this->seeded[$table] ?? 0);
            if ($extra > 0) {
                $also[] = [
                    'table' => $table,
                    'label' => $graph->label($table),
                    'count' => $extra,
                    'because' => collect($this->reasons[$table] ?? [])
                        ->map(fn (array $r): array => [...$r, 'label' => $graph->label($r['table'])])
                        ->values()
                        ->all(),
                ];
            }
        }

        $unlinked = [];
        foreach ($this->nullify as $entry) {
            if ($entry['loose'] && str_ends_with($entry['column'], '_type')) {
                continue; // the *_id half already represents this row
            }

            $unlinked[] = [
                'table' => $entry['table'],
                'label' => $graph->label($entry['table']),
                'column' => $entry['column'],
                'count' => count($entry['rows']),
            ];
        }

        return [
            'total_rows' => $this->totalRows(),
            'categories' => $categories,
            'also_deleted' => $also,
            'unlinked' => $unlinked,
            'warnings' => $this->warnings,
            'zero_stock' => $this->zeroStock,
            'tables' => $this->counts(),
            'fingerprint' => $this->fingerprint,
        ];
    }
}
