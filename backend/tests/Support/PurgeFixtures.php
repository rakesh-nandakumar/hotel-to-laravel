<?php

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Inserts a valid row into ANY table by reading its schema: required columns
 * get a generated value, required foreign keys get a freshly created parent,
 * optional ones stay NULL unless a test links them explicitly.
 *
 * Purge tests use it so they keep working (and keep covering every table)
 * when the schema grows, instead of hand-maintaining per-table inserts.
 */
final class PurgeFixtures
{
    private int $counter = 0;

    /** @var array<string, int> */
    private array $memo = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $columns = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $fks = [];

    /**
     * @param  array<string, mixed>  $overrides  column => value (use to link parents explicitly)
     */
    public function row(string $table, ?int $tenantId, array $overrides = []): int
    {
        $row = [];

        foreach ($this->columnsOf($table) as $column) {
            $name = $column['name'];

            if ($column['auto_increment'] || $name === 'id') {
                continue;
            }

            if (array_key_exists($name, $overrides)) {
                $row[$name] = $overrides[$name];

                continue;
            }

            if ($name === 'tenant_id') {
                $row[$name] = $tenantId;

                continue;
            }

            $fk = $this->fksOf($table)[$name] ?? null;

            if ($fk !== null) {
                if (! $column['nullable'] && $column['default'] === null) {
                    $row[$name] = $this->parentId($fk['foreign_table'], $tenantId);
                }

                continue;
            }

            if ($column['nullable'] || $column['default'] !== null) {
                continue;
            }

            $row[$name] = $this->valueFor($column);
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    /**
     * A lookup row for (type, code), created on first use.
     */
    public function lookup(string $type, string $code): int
    {
        return $this->memo["lookup:{$type}:{$code}"] ??= (int) (
            DB::table('lookups')->where('type', $type)->where('code', $code)->value('id')
            ?? DB::table('lookups')->insertGetId(['type' => $type, 'code' => $code, 'name' => ucfirst($code), 'is_active' => true])
        );
    }

    /**
     * One staff user per tenant, for every NOT NULL staff_id / run_by_id link.
     */
    public function user(int $tenantId): int
    {
        return $this->memo["user:{$tenantId}"] ??= $this->row('users', $tenantId, [
            'email' => "staff{$tenantId}@fixtures.test",
            'role_id' => null,
        ]);
    }

    private function parentId(string $table, ?int $tenantId): int
    {
        return match ($table) {
            'lookups' => $this->genericLookup(),
            'users' => $this->user((int) $tenantId),
            default => $this->row($table, $tenantId),
        };
    }

    private function genericLookup(): int
    {
        return $this->memo['lookup:generic'] ??= $this->lookup('generic', 'generic');
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function valueFor(array $column): mixed
    {
        $name = $column['name'];
        $type = strtolower((string) $column['type_name']);
        $full = strtolower((string) $column['type']);
        $n = ++$this->counter;

        return match (true) {
            $name === 'uuid' => (string) Str::ulid(),
            str_contains($name, 'email') => "fixture{$n}@fixtures.test",
            str_contains($name, 'token') => Str::random(40),
            $type === 'tinyint' && str_contains($full, '(1)') => 0,
            str_contains($type, 'int') => $n,
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => 1,
            $type === 'date' => Carbon::create(2026, 1, 1)->addDays($n)->toDateString(),
            in_array($type, ['datetime', 'timestamp'], true) => now()->toDateTimeString(),
            $type === 'time' => '10:00:00',
            $type === 'year' => 2026,
            $type === 'json' => '{}',
            $type === 'enum' && preg_match("/enum\('([^']+)'/i", $full, $m) === 1 => $m[1],
            default => "v{$n}",
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumns($table);
    }

    /**
     * @return array<string, array<string, mixed>> column => fk description
     */
    private function fksOf(string $table): array
    {
        return $this->fks[$table] ??= collect(Schema::getForeignKeys($table))
            ->filter(fn (array $fk): bool => count($fk['columns']) === 1)
            ->mapWithKeys(fn (array $fk): array => [$fk['columns'][0] => $fk])
            ->all();
    }
}
