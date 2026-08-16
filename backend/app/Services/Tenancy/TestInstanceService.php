<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Support\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Test instances (Master Control): provisions an isolated copy of a live tenant
 * and keeps it refreshed through an explicit "Sync from Live" action.
 *
 * A test instance is a full {@see Tenant} row with `environment = test` and a
 * `parent_tenant_id` pointing at the live tenant — so subdomain routing,
 * TenantScope isolation and the tenant application itself are shared verbatim.
 * What makes it a separate environment is that every owned row is duplicated
 * with a NEW primary key under the test tenant's own tenant_id. No row is ever
 * shared between the two environments; a sync atomically erases the test rows
 * and re-copies the current live state.
 *
 * The copy is a row-level engine driven by config/tenancy-clone.php:
 *   1. Tables are topologically ordered from their FK map (cycle edges — e.g.
 *      users ↔ roles — are kept as "pending").
 *   2. Rows are bulk-inserted with new ids; ids are recovered from the
 *      connection's LAST_INSERT_ID (multi-row inserts allocate consecutive ids
 *      in VALUES order within one statement).
 *   3. A second pass remaps every pending FK column via CASE updates, and
 *      exhaustively verifies cloned row counts before the transaction commits —
 *      any mismatch rolls back the entire sync.
 */
class TestInstanceService
{
    private const CHUNK = 500;

    public function create(Tenant $live, ?int $createdBy = null): Tenant
    {
        if ($live->environment === Tenant::ENV_TEST) {
            throw new RuntimeException('A test instance cannot have its own test instance.');
        }

        $slug = self::testSlugFor($live);

        if (Tenant::query()->withTrashed()->where('slug', $slug)->exists()) {
            throw new RuntimeException(sprintf(
                'The subdomain "%s.%s" is already taken. Remove the conflicting tenant first.',
                $slug,
                config('tenancy.base_domain'),
            ));
        }

        $test = DB::transaction(function () use ($live, $slug, $createdBy): Tenant {
            $test = Tenant::create([
                'name' => $live->name.' (Test)',
                'slug' => $slug,
                'status' => TenantStatus::ACTIVE,
                'environment' => Tenant::ENV_TEST,
                'parent_tenant_id' => $live->id,
                'created_by' => $createdBy,
            ]);

            $this->cloneInto($live, $test);

            $test->update([
                'last_synced_at' => now(),
                'last_synced_by' => $createdBy,
            ]);

            return $test->fresh();
        });

        $this->flushUserPermissionCaches($test);

        return $test;
    }

    /**
     * Refresh a test instance from the current state of its live parent.
     * Erases the existing test data first, then copies the live state.
     *
     * @return array<string, int|float>
     */
    public function syncFromLive(Tenant $test, ?int $syncedBy = null): array
    {
        $live = $test->parentTenant;

        if (! $live) {
            throw new RuntimeException('This test instance has no parent tenant to sync from.');
        }

        $result = DB::transaction(function () use ($live, $test): array {
            return $this->cloneInto($live, $test, wipe: true);
        });

        $test->update([
            'last_synced_at' => now(),
            'last_synced_by' => $syncedBy,
        ]);

        $this->flushUserPermissionCaches($test);

        return $result;
    }

    /**
     * Permanently delete a test instance and every row it owns. The parent
     * live tenant is never touched.
     */
    public function destroy(Tenant $test): void
    {
        DB::transaction(function () use ($test): void {
            $config = config('tenancy-clone');
            [$order] = self::orderedTables($config);

            $this->wipeOwnedTables($test, $config, array_reverse($order));
            $this->wipePivots($test, $config);
            $test->forceDelete();
        });
    }

    /**
     * The subdomain a test instance of $live occupies: the live slug with
     * "-test" appended, trimmed to the 63-char slug limit.
     */
    public static function testSlugFor(Tenant $live): string
    {
        return mb_substr($live->slug, 0, 63 - 5).'-test';
    }

    /**
     * Copy the live tenant's current state into the test instance.
     *
     * @return array<string, int|float> summary of copied rows
     */
    private function cloneInto(Tenant $live, Tenant $test, bool $wipe = false): array
    {
        $config = config('tenancy-clone');
        $start = microtime(true);

        [$order] = self::orderedTables($config);

        if ($wipe) {
            $this->wipeOwnedTables($test, $config, array_reverse($order));
            $this->wipePivots($test, $config);
        }

        $maps = [];
        $pending = ['fks' => [], 'json' => [], 'morph' => []];
        $dropped = [];

        foreach ($order as $table) {
            $def = $config['tables'][$table];
            [$maps[$table], $pending, $dropped[$table]] = $this->cloneTable($live, $test, $table, $def, $config, $maps, $pending);
        }

        $this->clonePivots($live, $test, $config, $maps);

        $this->resolvePending($test, $config, $maps, $pending, $dropped);

        $this->verifyClone($live, $test, $config, $dropped);

        return $this->summary($test, $config, $dropped) + ['seconds' => round(microtime(true) - $start, 1)];
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @return array{0: list<string>} topological clone order (parents first)
     */
    private static function orderedTables(array $config): array
    {
        $tables = $config['tables'];
        $names = array_keys($tables);

        $deps = [];
        foreach ($tables as $name => $def) {
            $deps[$name] = collect($def['fks'] ?? [])
                ->values()
                ->unique()
                ->reject(fn (string $target): bool => $target === $name || ! isset($tables[$target]))
                ->values()
                ->all();
        }

        $order = [];
        $processed = [];

        while (count($processed) < count($names)) {
            $progress = false;

            foreach ($names as $name) {
                if (isset($processed[$name])) {
                    continue;
                }

                $unresolved = array_values(array_filter(
                    $deps[$name],
                    fn (string $dep): bool => ! isset($processed[$dep]),
                ));

                if ($unresolved === []) {
                    $processed[$name] = true;
                    $order[] = $name;
                    $progress = true;
                }
            }

            if (! $progress) {
                // Cycle (users ↔ roles etc.): force the remaining tables into
                // config order; their blocking edges are deferred to pass 2.
                foreach ($names as $name) {
                    if (! isset($processed[$name])) {
                        $processed[$name] = true;
                        $order[] = $name;
                    }
                }
                break;
            }
        }

        return [$order];
    }

    /**
     * @param  array<string, array<string, mixed>>  $maps  table => [oldId => newId]
     * @param  array<string, mixed>  $pending
     * @return array{0: array<string, int>, 1: array<string, mixed>, 2: int} map, pending, dropped rows
     */
    private function cloneTable(Tenant $live, Tenant $test, string $table, array $def, array $config, array $maps, array $pending): array
    {
        $query = DB::table($table)->where('tenant_id', $live->id);

        $map = [];
        $dropped = 0;
        $nullable = $this->nullableColumnsFor($table);

        $query->chunkById(self::CHUNK, function (Collection $rows) use ($live, $test, $table, $def, $config, $maps, $nullable, &$map, &$pending, &$dropped): void {
            $built = [];
            $deferredByTarget = [];

            foreach ($rows as $row) {
                $new = (array) $row;
                $oldId = (int) $new['id'];
                unset($new['id']);

                $new['tenant_id'] = $test->id;

                if (array_key_exists('uuid', $new)) {
                    // uuid columns are globally unique — a copy must mint its own.
                    $new['uuid'] = (string) Str::ulid();
                }

                foreach ($def['unique_columns'] ?? [] as $col) {
                    // Globally unique columns (qr_ordering_points.token) collide
                    // with the live row's value — mint a fresh one.
                    if (array_key_exists($col, $new)) {
                        $new[$col] = (string) Str::random(40);
                    }
                }

                $fkPending = [];
                $drop = false;

                foreach (($def['fks'] ?? []) as $col => $target) {
                    if (! array_key_exists($col, $new) || $new[$col] === null) {
                        continue;
                    }

                    $oldVal = (int) $new[$col];

                    if (isset($maps[$target][$oldVal])) {
                        $new[$col] = $maps[$target][$oldVal];
                    } elseif (isset($maps[$target])) {
                        // Target already cloned but this exact row is not part of
                        // it — a dangling/cross-tenant reference in live data.
                        // Null it when the column allows; otherwise the row
                        // cannot be represented and is skipped.
                        if ($nullable[$col] ?? false) {
                            $new[$col] = null;
                        } else {
                            $drop = true;
                            break;
                        }
                    } elseif (isset($config['tables'][$target])) {
                        // Cycle edge / self-reference: the target is cloned but
                        // its id map isn't ready yet. Resolve in pass 2, after
                        // confirming the referenced row exists in live data.
                        $fkPending[$col] = [$target, $oldVal];
                    }
                    // Else: global reference (lookups.id etc.) — copied verbatim.
                }

                foreach (array_keys($def['json_remap'] ?? []) as $col) {
                    if (array_key_exists($col, $new)) {
                        $pending['json'][$table][$col][$oldId] = true;
                    }
                }

                $poly = $config['polymorphic'][$table] ?? null;
                if ($poly !== null && ($new[$poly['type']] ?? null) && ($new[$poly['id']] ?? null) !== null) {
                    $pending['morph'][$table][$poly['id']][(int) $new[$poly['id']]] = true;
                }

                if ($drop) {
                    $dropped++;

                    continue;
                }

                foreach ($fkPending as $col => [$target, $oldVal]) {
                    $deferredByTarget[$target][$oldVal] = true;
                }

                $built[] = ['oldId' => $oldId, 'new' => $new, 'fkPending' => $fkPending];
            }

            // Deferred FKs keep their raw id (which must exist) until pass 2;
            // values that don't exist in the live tenant are dangling and are
            // nulled or dropped right here, before the insert runs.
            if ($deferredByTarget !== []) {
                foreach ($deferredByTarget as $target => $values) {
                    $existing = DB::table($target)
                        ->whereIn('id', array_keys($values))
                        ->where('tenant_id', $live->id)
                        ->pluck('id')
                        ->all();
                    $existing = array_flip($existing);

                    $kept = [];

                    foreach ($built as $entry) {
                        if ($entry === null || $entry['fkPending'] === []) {
                            $kept[] = $entry;

                            continue;
                        }

                        $drop = false;

                        foreach ($entry['fkPending'] as $col => [$t, $oldVal]) {
                            if ($t !== $target) {
                                continue;
                            }

                            if (isset($existing[$oldVal])) {
                                $pending['fks'][$table][$col][$oldVal] = true;
                            } elseif ($nullable[$col] ?? false) {
                                $entry['new'][$col] = null;
                            } else {
                                $drop = true;
                                break;
                            }
                        }

                        if ($drop) {
                            $dropped++;
                            $kept[] = null;
                        } else {
                            $kept[] = $entry;
                        }
                    }

                    $built = $kept;
                }
            }

            $newRows = [];
            $keptOldIds = [];

            foreach ($built as $entry) {
                if ($entry === null) {
                    continue;
                }
                $newRows[] = $entry['new'];
                $keptOldIds[] = $entry['oldId'];
            }

            if ($newRows === []) {
                return;
            }

            $firstId = $this->insertRows($table, $newRows);

            foreach ($keptOldIds as $i => $oldId) {
                $map[$oldId] = $firstId + $i;
            }
        });

        return [$map, $pending, $dropped];
    }

    /**
     * Bulk-insert copied rows and return the auto-increment id of the FIRST
     * row in the batch. PDO's lastInsertId() reports the first id of a
     * multi-row insert on MySQL and the last rowid on SQLite — rows are
     * consecutive within one statement, so both resolve to the same base.
     */
    private function insertRows(string $table, array $rows): int
    {
        if (! DB::table($table)->insert($rows)) {
            throw new RuntimeException("Failed to clone rows into \"{$table}\".");
        }

        $last = (int) DB::connection()->getPdo()->lastInsertId();

        return DB::getDriverName() === 'mysql' ? $last : $last - (count($rows) - 1);
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<string, array<string, int>>  $maps
     */
    private function clonePivots(Tenant $live, Tenant $test, array $config, array $maps): void
    {
        foreach ($config['pivots'] as $pivot => $def) {
            $remapCols = collect($def['fks'])
                ->reject(fn ($target, string $col): bool => in_array($col, $def['skip_remap'] ?? [], true));

            $liveIds = $remapCols->mapWithKeys(fn (string $target, string $col): array => [$col => array_keys($maps[$target])]);

            $rows = DB::table($pivot)
                ->where(function (QueryBuilder $q) use ($liveIds): void {
                    foreach ($liveIds as $col => $ids) {
                        $q->orWhereIn($col, $ids);
                    }
                })
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $newRows = $rows->map(function (object $row) use ($remapCols, $maps): array {
                $new = (array) $row;

                foreach ($remapCols as $col => $target) {
                    if ($new[$col] === null) {
                        continue;
                    }
                    $new[$col] = $maps[$target][(int) $new[$col]] ?? null;
                }

                return $new;
            });

            // Drop rows whose only remapped columns all came out unresolved —
            // keeping them would leave a dangling pair pointing at live data.
            $newRows = $newRows->filter(function (array $row) use ($remapCols): bool {
                foreach ($remapCols as $col => $target) {
                    if (($row[$col] ?? null) !== null) {
                        return true;
                    }
                }

                return false;
            });

            foreach (array_chunk($newRows->values()->all(), self::CHUNK) as $chunk) {
                DB::table($pivot)->insert($chunk);
            }
        }
    }

    /**
     * Pass 2: remap everything that couldn't be resolved while the target's id
     * map did not exist yet — deferred FK columns (CASE updates) and
     * polymorphic references (per-row).
     *
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<string, array<string, int>>  $maps
     * @param  array<string, mixed>  $pending
     * @param  array<string, int>  $dropped
     */
    private function resolvePending(Tenant $test, array $config, array $maps, array $pending, array &$dropped): void
    {
        foreach ($pending['fks'] as $table => $cols) {
            $def = $config['tables'][$table];
            $nullable = $this->nullableColumnsFor($table);

            foreach ($cols as $col => $oldValues) {
                $target = $def['fks'][$col];

                if (! isset($maps[$target])) {
                    continue;
                }

                if (! isset($dropped[$table])) {
                    $dropped[$table] = 0;
                }

                foreach (array_chunk(array_keys($oldValues), 500) as $chunk) {
                    $this->remapColumn($test, $table, $def, $col, $chunk, $maps[$target], (bool) ($nullable[$col] ?? false), $dropped[$table]);
                }
            }
        }

        foreach ($pending['morph'] as $table => $idCols) {
            $poly = $config['polymorphic'][$table];

            foreach ($idCols as $idCol => $oldIds) {
                foreach (array_keys($oldIds) as $oldId) {
                    $this->remapMorphColumn($test, $table, $poly, $idCol, (int) $oldId, $maps);
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $map
     */
    private function remapColumn(Tenant $test, string $table, array $def, string $col, array $oldValues, array $map, bool $nullable, int &$dropped): void
    {
        $mapped = [];
        $unmapped = [];

        foreach ($oldValues as $old) {
            $old = (int) $old;

            if (isset($map[$old])) {
                $mapped[] = $old;
            } else {
                $unmapped[] = $old;
            }
        }

        $scope = 'AND `tenant_id` = ?';

        // References that can never resolve cannot be represented on a NOT NULL
        // column — drop those rows instead of violating the constraint.
        if ($unmapped !== [] && ! $nullable) {
            $in = implode(',', array_fill(0, count($unmapped), '?'));
            $bindings = [...$unmapped, $test->id];
            $dropped += (int) DB::delete("DELETE FROM `{$table}` WHERE `{$col}` IN ({$in}) {$scope}", $bindings);
        }

        if ($mapped === []) {
            // Nothing to remap; the CASE below nulls the dangling rows.
            if ($nullable && $unmapped !== []) {
                $in = implode(',', array_fill(0, count($unmapped), '?'));
                $bindings = [...$unmapped, $test->id];
                DB::update(
                    "UPDATE `{$table}` SET `{$col}` = CASE `{$col}` ELSE NULL END WHERE `{$col}` IN ({$in}) {$scope}",
                    $bindings,
                );
            }

            return;
        }

        $cases = '';
        $bindings = [];

        foreach ($mapped as $old) {
            $cases .= 'WHEN ? THEN ? ';
            $bindings[] = $old;
            $bindings[] = (int) $map[$old];
        }

        if ($nullable && $unmapped !== []) {
            $all = [...$mapped, ...$unmapped];
            $in = implode(',', array_fill(0, count($all), '?'));
            $bindings = [...$bindings, ...$all];
            $sql = "UPDATE `{$table}` SET `{$col}` = CASE `{$col}` {$cases}ELSE NULL END "
                ."WHERE `{$col}` IN ({$in}) {$scope}";
        } else {
            $in = implode(',', array_fill(0, count($mapped), '?'));
            $bindings = [...$bindings, ...$mapped];
            $sql = "UPDATE `{$table}` SET `{$col}` = CASE `{$col}` {$cases}END "
                ."WHERE `{$col}` IN ({$in}) {$scope}";
        }

        $bindings[] = $test->id;

        DB::update($sql, $bindings);
    }

    /**
     * @param  array<string, mixed>  $poly
     * @param  array<string, array<string, int>>  $maps
     */
    private function remapMorphColumn(Tenant $test, string $table, array $poly, string $idCol, int $oldId, array $maps): void
    {
        $rows = DB::table($table)
            ->where('tenant_id', $test->id)
            ->where($idCol, $oldId)
            ->select('id', $poly['type'])
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows->groupBy($poly['type']) as $type => $group) {
            $target = $this->morphTable($type);

            if ($target === null || ! isset($maps[$target][$oldId])) {
                continue;
            }

            DB::table($table)
                ->whereIn('id', $group->pluck('id'))
                ->update([$idCol => $maps[$target][$oldId]]);
        }
    }

    private function morphTable(mixed $type): ?string
    {
        if (! is_string($type) || ! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return null;
        }

        try {
            return (new $type)->getTable();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @param  list<string>  $reverseOrder
     */
    private function wipeOwnedTables(Tenant $test, array $config, array $reverseOrder): void
    {
        foreach ($reverseOrder as $table) {
            DB::table($table)->where('tenant_id', $test->id)->delete();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     */
    private function wipePivots(Tenant $test, array $config): void
    {
        foreach ($config['pivots'] as $pivot => $def) {
            $remapCols = collect($def['fks'])
                ->reject(fn ($target, string $col): bool => in_array($col, $def['skip_remap'] ?? [], true));

            DB::table($pivot)
                ->where(function (QueryBuilder $q) use ($test, $remapCols): void {
                    foreach ($remapCols as $col => $target) {
                        $q->orWhereIn($col, DB::table($target)->where('tenant_id', $test->id)->select('id'));
                    }
                })
                ->delete();
        }
    }

    /**
     * Every cloned row must land in the test instance — a single shortfall
     * (id mapping drift, a hard constraint) aborts the whole sync. The only
     * permitted shortfall is a row skipped on purpose because it referenced a
     * dangling/cross-tenant row on a NOT NULL FK and cannot be represented.
     *
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<string, int>  $dropped  table => rows skipped as unresolvable
     */
    private function verifyClone(Tenant $live, Tenant $test, array $config, array $dropped): void
    {
        foreach ($config['tables'] as $table => $def) {
            $liveCount = $this->countFor($live, $table);
            $testCount = $this->countFor($test, $table);
            $skipped = $dropped[$table] ?? 0;

            if ($liveCount !== $testCount + $skipped) {
                throw new RuntimeException(sprintf(
                    'Clone verification failed on "%s": live has %d rows, test copy has %d (%d skipped as unresolvable). The sync was rolled back — nothing changed.',
                    $table,
                    $liveCount,
                    $testCount,
                    $skipped,
                ));
            }
        }
    }

    private function countFor(Tenant $tenant, string $table): int
    {
        return (int) DB::table($table)->where('tenant_id', $tenant->id)->count();
    }

    /**
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<string, int>  $dropped
     * @return array<string, int|float>
     */
    private function summary(Tenant $test, array $config, array $dropped): array
    {
        $headline = ['users' => 'users', 'branches' => 'warehouses', 'guests' => 'guests', 'rooms' => 'rooms', 'reservations' => 'reservations', 'orders' => 'orders'];
        $out = [];
        $total = 0;
        $droppedRows = 0;

        foreach ($config['tables'] as $table => $def) {
            $count = $this->countFor($test, $table);
            $total += $count;
            $droppedRows += $dropped[$table] ?? 0;
            $label = array_search($table, $headline, true);

            if ($label !== false) {
                $out[$label] = $count;
            }
        }

        $out['total_rows'] = $total;
        $out['dropped_rows'] = $droppedRows;

        return $out;
    }

    /**
     * Nullability per column, used to decide whether a dangling FK reference
     * can be nulled or forces the row to be skipped.
     *
     * @return array<string, bool>
     */
    private function nullableColumnsFor(string $table): array
    {
        return collect(Schema::getColumns($table))
            ->mapWithKeys(fn (array $column): array => [$column['name'] => (bool) ($column['nullable'] ?? false)])
            ->all();
    }

    private function flushUserPermissionCaches(Tenant $test): void
    {
        DB::table('users')->where('tenant_id', $test->id)->pluck('id')->each(function (int $id): void {
            Cache::forget("user:{$id}:permissions");
            Cache::forget("user:{$id}:full_admin");
        });
    }
}
