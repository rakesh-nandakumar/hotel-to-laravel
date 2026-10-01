<?php

namespace App\Services\Tenancy\DataPurge;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The operator-facing side of config/tenancy-purge.php: describes the
 * categories (with live counts and filter options) and turns whatever the
 * client submitted into a strict, canonical selection list.
 */
final class PurgeCatalog
{
    public function __construct(private readonly PurgeGraph $graph) {}

    /**
     * @return list<array<string, mixed>> modules, each with its categories
     */
    public function describe(int $tenantId): array
    {
        $modules = [];

        foreach (config('tenancy-purge.modules') as $moduleKey => $moduleLabel) {
            $categories = [];

            foreach (config('tenancy-purge.categories') as $key => $category) {
                if ($category['module'] !== $moduleKey) {
                    continue;
                }

                $roots = [];
                foreach ($category['roots'] as $table) {
                    $roots[] = [
                        'table' => $table,
                        'label' => $this->graph->label($table),
                        'count' => (int) DB::table($table)->where('tenant_id', $tenantId)->count(),
                    ];
                }

                $categories[] = [
                    'key' => $key,
                    'label' => $category['label'],
                    'description' => $category['description'],
                    'master' => (bool) ($category['master'] ?? false),
                    'count' => array_sum(array_column($roots, 'count')),
                    'roots' => $roots,
                    'filters' => array_map(fn (array $spec): array => $this->describeFilter($spec), $category['filters']),
                ];
            }

            $modules[] = ['key' => $moduleKey, 'label' => $moduleLabel, 'categories' => $categories];
        }

        return $modules;
    }

    /**
     * Validates and canonicalises submitted selections. The result is stable
     * (sorted, no empty filters), so it can be fingerprinted and round-tripped
     * through a signed token.
     *
     * @return list<array{key: string, filters: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public function normalize(mixed $input): array
    {
        if (! is_array($input) || $input === []) {
            throw self::invalid('selections', 'Select at least one data category.');
        }

        $seen = [];
        $selections = [];

        foreach ($input as $i => $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            $category = is_string($key) ? config("tenancy-purge.categories.{$key}") : null;

            if ($category === null) {
                throw self::invalid("selections.{$i}.key", 'Unknown data category.');
            }

            if (isset($seen[$key])) {
                throw self::invalid("selections.{$i}.key", "\"{$category['label']}\" is selected twice.");
            }
            $seen[$key] = true;

            $specs = collect($category['filters'])->keyBy('key');
            $given = is_array($item['filters'] ?? null) ? $item['filters'] : [];
            $filters = [];

            foreach ($given as $filterKey => $value) {
                $spec = $specs->get($filterKey);

                if ($spec === null) {
                    throw self::invalid("selections.{$i}.filters", "\"{$filterKey}\" is not a filter of \"{$category['label']}\".");
                }

                $normalised = $this->normalizeFilter($spec, $value, "selections.{$i}.filters.{$filterKey}");

                if ($normalised !== null) {
                    $filters[$filterKey] = $normalised;
                }
            }

            ksort($filters);
            $selections[] = ['key' => $key, 'filters' => $filters];
        }

        usort($selections, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $selections;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function describeFilter(array $spec): array
    {
        $described = ['key' => $spec['key'], 'label' => $spec['label'], 'type' => $spec['type']];

        if ($spec['type'] === 'lookup') {
            $described['options'] = DB::table('lookups')
                ->where('type', $spec['lookup'])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name])
                ->all();
        }

        return $described;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|list<int>|null null when the filter is effectively empty
     */
    private function normalizeFilter(array $spec, mixed $value, string $path): ?array
    {
        if (! is_array($value)) {
            throw self::invalid($path, 'Invalid filter value.');
        }

        if ($spec['type'] === 'lookup') {
            $ids = array_values(array_unique(array_map('intval', array_filter($value, 'is_numeric'))));
            sort($ids);

            if ($ids === []) {
                return null;
            }

            $known = DB::table('lookups')->where('type', $spec['lookup'])->whereIn('id', $ids)->count();

            if ($known !== count($ids)) {
                throw self::invalid($path, 'Unknown option selected.');
            }

            return $ids;
        }

        $format = $spec['type'] === 'month' ? 'Y-m' : 'Y-m-d';
        $from = $this->parseDate($value['from'] ?? null, $format, $path);
        $to = $this->parseDate($value['to'] ?? null, $format, $path);

        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw self::invalid($path, 'The "from" value is after the "to" value.');
        }

        return ['from' => $from, 'to' => $to];
    }

    private function parseDate(mixed $value, string $format, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!'.$format, $value) : false;

        if ($parsed === false || $parsed->format($format) !== $value) {
            throw self::invalid($path, 'Use the format '.($format === 'Y-m' ? 'YYYY-MM' : 'YYYY-MM-DD').'.');
        }

        return $value;
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
