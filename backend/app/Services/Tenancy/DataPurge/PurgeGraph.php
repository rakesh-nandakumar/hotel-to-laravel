<?php

namespace App\Services\Tenancy\DataPurge;

use App\Services\Tenancy\TableTopology;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/**
 * The dependency graph between a tenant's tables, read from the live schema's
 * real foreign keys and adjusted by config/tenancy-purge.php.
 *
 * "Scope" tables are the tenant tables (they carry tenant_id) that are not
 * listed as protected — the only tables a purge may ever delete from.
 */
#[Singleton]
final class PurgeGraph
{
    public const CASCADE = 'cascade';

    public const NULLIFY = 'nullify';

    /** @var array{tables: array<string, array<string, bool>>, fks: array<string, list<array{column: string, parent: string, nullable: bool, on_delete: string}>>}|null */
    private ?array $schema = null;

    /** @var list<string>|null */
    private ?array $scopeTables = null;

    /** @var list<array{child: string, column: string, parent: string, policy: string, nullable: bool}>|null */
    private ?array $edges = null;

    /** @var array<string, list<array{child: string, column: string, parent: string, policy: string, nullable: bool}>> */
    private array $childrenIndex = [];

    /**
     * @return list<string> tables a purge may delete from (tenant tables minus protected)
     */
    public function scopeTables(): array
    {
        if ($this->scopeTables === null) {
            $protected = config('tenancy-purge.protected', []);

            $this->scopeTables = array_values(array_filter(
                array_keys($this->schema()['tables']),
                fn (string $table): bool => ! in_array($table, $protected, true),
            ));
        }

        return $this->scopeTables;
    }

    /**
     * @return list<string> every table carrying a tenant_id column
     */
    public function tenantTables(): array
    {
        return array_keys($this->schema()['tables']);
    }

    public function isScope(string $table): bool
    {
        return in_array($table, $this->scopeTables(), true);
    }

    /**
     * @return array<string, bool> column => nullable, for a tenant table
     */
    public function columns(string $table): array
    {
        return $this->schema()['tables'][$table] ?? [];
    }

    /**
     * Every single-column FK of a table (to scope or non-scope parents alike),
     * without the tenant_id → tenants link.
     *
     * @return list<array{column: string, parent: string, nullable: bool, on_delete: string}>
     */
    public function foreignKeys(string $table): array
    {
        return $this->schema()['fks'][$table] ?? [];
    }

    /**
     * Parent → child relationships between scope tables, with what a purge does
     * to the child when the parent goes.
     *
     * @return list<array{child: string, column: string, parent: string, policy: string, nullable: bool}>
     */
    public function edges(): array
    {
        if ($this->edges === null) {
            $this->edges = [];
            $overrides = config('tenancy-purge.edge_overrides', []);

            foreach ($this->scopeTables() as $child) {
                foreach ($this->foreignKeys($child) as $fk) {
                    if (! $this->isScope($fk['parent'])) {
                        continue;
                    }

                    $override = $overrides["{$child}.{$fk['column']}"] ?? null;

                    if ($override === self::NULLIFY && ! $fk['nullable']) {
                        throw new LogicException("Edge override {$child}.{$fk['column']} asks to nullify a NOT NULL column.");
                    }

                    $policy = $override ?? ($fk['on_delete'] === 'set null' ? self::NULLIFY : self::CASCADE);

                    $edge = [
                        'child' => $child,
                        'column' => $fk['column'],
                        'parent' => $fk['parent'],
                        'policy' => $policy,
                        'nullable' => $fk['nullable'],
                    ];

                    $this->edges[] = $edge;
                    $this->childrenIndex[$fk['parent']][] = $edge;
                }
            }
        }

        return $this->edges;
    }

    /**
     * @return list<array{child: string, column: string, parent: string, policy: string, nullable: bool}>
     */
    public function childrenOf(string $parent): array
    {
        $this->edges();

        return $this->childrenIndex[$parent] ?? [];
    }

    /**
     * What purging an FK's parent does to the child column, or null when the
     * parent is not a scope table.
     */
    public function policyFor(string $child, string $column): ?string
    {
        foreach ($this->edges() as $edge) {
            if ($edge['child'] === $child && $edge['column'] === $column) {
                return $edge['policy'];
            }
        }

        return null;
    }

    /**
     * Owner promotions: a row of `child` pulled into a purge drags the row its
     * `column` points at along.
     *
     * @return list<array{child: string, column: string, parent: string}>
     */
    public function ownersOf(string $child): array
    {
        $owners = [];

        foreach (config('tenancy-purge.owners', []) as $owner) {
            if ($owner['child'] !== $child) {
                continue;
            }

            foreach ($this->foreignKeys($child) as $fk) {
                if ($fk['column'] === $owner['column']) {
                    $owners[] = ['child' => $child, 'column' => $fk['column'], 'parent' => $fk['parent']];
                }
            }
        }

        return $owners;
    }

    /**
     * @return list<array{child: string, type_column: string, id_column: string, type_value: string, parent: string, policy: string}>
     */
    public function looseEdgesFrom(string $parent): array
    {
        $edges = [];

        foreach (config('tenancy-purge.loose_edges', []) as $loose) {
            foreach ($loose['targets'] as $typeValue => $table) {
                if ($table === $parent) {
                    $edges[] = [
                        'child' => $loose['child'],
                        'type_column' => $loose['type_column'],
                        'id_column' => $loose['id_column'],
                        'type_value' => (string) $typeValue,
                        'parent' => $table,
                        'policy' => $loose['policy'],
                    ];
                }
            }
        }

        return $edges;
    }

    /**
     * Children before parents, following only edges that force a delete order.
     *
     * @return list<string>
     */
    public function deletionOrder(): array
    {
        $deps = array_fill_keys($this->scopeTables(), []);

        foreach ($this->edges() as $edge) {
            if ($edge['policy'] === self::CASCADE) {
                $deps[$edge['parent']][] = $edge['child'];
            }
        }

        return TableTopology::order($deps);
    }

    /**
     * Parents before children for re-inserting rows: a NOT NULL or
     * ownership FK always has its parent first. Nullable "unlink" FKs may
     * point forward and are deferred by the restore.
     *
     * @return list<string>
     */
    public function insertOrder(): array
    {
        $deps = array_fill_keys($this->scopeTables(), []);

        foreach ($this->edges() as $edge) {
            if ($edge['policy'] === self::CASCADE || ! $edge['nullable']) {
                $deps[$edge['child']][] = $edge['parent'];
            }
        }

        return TableTopology::order($deps);
    }

    /**
     * Places where a purge would silently touch a table it must not: a
     * protected or untenanted table holding an FK into a scope table.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->schema()['fks'] as $child => $fks) {
            if ($this->isScope($child)) {
                continue;
            }

            foreach ($fks as $fk) {
                if ($this->isScope($fk['parent'])) {
                    $violations[] = "{$child}.{$fk['column']} → {$fk['parent']}";
                }
            }
        }

        return $violations;
    }

    public function label(string $table): string
    {
        return config("tenancy-purge.labels.{$table}")
            ?? Str::of($table)->replace('_', ' ')->ucfirst()->toString();
    }

    /**
     * @return array{tables: array<string, array<string, bool>>, fks: array<string, list<array{column: string, parent: string, nullable: bool, on_delete: string}>>}
     */
    private function schema(): array
    {
        if ($this->schema === null) {
            $this->schema = Cache::remember($this->schemaCacheKey(), 3600, fn (): array => $this->introspect());
        }

        return $this->schema;
    }

    /**
     * Introspection costs hundreds of catalog queries on MySQL, so it is cached
     * against the migration state — a schema change always adds a migration.
     */
    private function schemaCacheKey(): string
    {
        $state = DB::table('migrations')->selectRaw('count(*) as n, max(id) as last')->first();

        return sprintf('tenancy-purge:schema:v1:%s:%d:%d', DB::getDriverName(), $state->n ?? 0, $state->last ?? 0);
    }

    /**
     * @return array{tables: array<string, array<string, bool>>, fks: array<string, list<array{column: string, parent: string, nullable: bool, on_delete: string}>>}
     */
    private function introspect(): array
    {
        $tables = [];
        $fks = [];
        $allColumns = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $columns = [];

            foreach (Schema::getColumns($name) as $column) {
                $columns[$column['name']] = (bool) $column['nullable'];
            }

            $allColumns[$name] = $columns;

            if (isset($columns['tenant_id'])) {
                $tables[$name] = $columns;
            }
        }

        foreach ($allColumns as $name => $columns) {
            foreach (Schema::getForeignKeys($name) as $fk) {
                if (count($fk['columns']) !== 1 || $fk['columns'][0] === 'tenant_id') {
                    continue;
                }

                $column = $fk['columns'][0];

                $fks[$name][] = [
                    'column' => $column,
                    'parent' => $fk['foreign_table'],
                    'nullable' => $columns[$column] ?? false,
                    'on_delete' => strtolower((string) $fk['on_delete']),
                ];
            }
        }

        return ['tables' => $tables, 'fks' => $fks];
    }
}
