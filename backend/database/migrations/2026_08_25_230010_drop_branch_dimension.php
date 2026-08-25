<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the branch dimension from the schema. Tenancy remains the only
 * isolation boundary (see App\Models\Concerns\BelongsToTenant); branches were
 * a second, sub-tenant scoping layer (App\Models\Concerns\BelongsToBranch,
 * now removed) that only ever applied to four tables: rooms, venues,
 * apartment_properties and tills.
 *
 * Uniqueness on those four tables was (branch_id, x); since a branch belongs
 * to exactly one tenant, this collapses to (tenant_id, x) rather than being
 * dropped outright — two tenants can still both have a "Room 101", one
 * tenant cannot have it twice. guardAgainstDuplicates() fails loudly, naming
 * offenders, if that ever wouldn't hold (soft-deleted rows are excluded from
 * the check — they don't participate in the new unique key either).
 *
 * Step order (FK, then index, then column) is what both drivers need:
 *  - MySQL: branch_id is the leftmost column of the composite unique, so
 *    that index backs the foreign key. Dropping the index first fails with
 *    errno 150 — the FK has to go first.
 *  - SQLite (tests): foreign keys cannot be dropped by name at all —
 *    SQLiteGrammar::compileDropForeign() throws — so step 1 is a no-op there
 *    and the FK leaves with the column in step 3.
 */
return new class extends Migration
{
    /**
     * table => [old branch-scoped unique index, new tenant-scoped columns]
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const UNIQUE_REWRITES = [
        'rooms' => ['rooms_branch_id_number_unique', ['tenant_id', 'number']],
        'venues' => ['venues_branch_id_name_unique', ['tenant_id', 'name']],
        'apartment_properties' => ['apartment_properties_branch_id_name_unique', ['tenant_id', 'name']],
        'tills' => ['tills_branch_id_name_unique', ['tenant_id', 'name']],
    ];

    /**
     * Tables carrying a branch_id FK into `warehouses`.
     *
     * @var list<string>
     */
    private const BRANCH_TABLES = ['rooms', 'venues', 'apartment_properties', 'tills'];

    public function up(): void
    {
        // ── 1. Drop the FK, before the index backing it (MySQL); no-op on SQLite ──
        foreach (self::BRANCH_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            try {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['branch_id']);
                });
            } catch (Throwable $e) {
                // SQLite (unsupported by the grammar), already gone, or created
                // under a non-conventional name. On MySQL a genuine failure here
                // resurfaces at the column drop in step 3, which is unguarded.
            }
        }

        // ── 2. Re-point uniqueness from the branch to the tenant ──────────────────
        foreach (self::UNIQUE_REWRITES as $table => [$oldIndex, $newColumns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasIndex($table, $oldIndex)) {
                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($oldIndex) {
                        $blueprint->dropUnique($oldIndex);
                    });
                } catch (Throwable $e) {
                    // Non-conventional name; the column drop below still proceeds.
                }
            }

            $newIndex = $table.'_'.implode('_', $newColumns).'_unique';

            if (! Schema::hasIndex($table, $newIndex)) {
                $this->guardAgainstDuplicates($table, $newColumns);

                Schema::table($table, function (Blueprint $blueprint) use ($newColumns) {
                    $blueprint->unique($newColumns);
                });
            }
        }

        // ── 3. Drop the column ──────────────────────────────────────────────────
        foreach (self::BRANCH_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('branch_id');
                });
            }
        }

        // ── 4. The branch entity itself ─────────────────────────────────────────
        // Access list first: it holds the last remaining FK into `warehouses`.
        Schema::dropIfExists('user_warehouse_access');
        Schema::dropIfExists('warehouses');
    }

    public function down(): void
    {
        // Deliberately irreversible. Which branch each room, venue, property and
        // till belonged to is not recoverable once the column is gone, and
        // recreating `warehouses` would restore the shape but not the rows —
        // leaving a NOT NULL FK the application can no longer satisfy. The
        // branch concept is gone from the code as well, so there is nothing for
        // a restored schema to talk to. Roll forward, or restore from a backup.
    }

    /**
     * A branch-scoped key allowed the same value twice inside one tenant, once
     * per branch. Collapsing to (tenant_id, x) can therefore collide on real
     * data. Fail loudly and name the offenders rather than silently shipping a
     * table with no uniqueness guarantee. Soft-deleted rows are excluded — they
     * don't participate in the new unique key either.
     *
     * @param  list<string>  $columns
     */
    private function guardAgainstDuplicates(string $table, array $columns): void
    {
        $duplicates = DB::table($table)
            ->whereNull('deleted_at')
            ->select($columns)
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $rendered = $duplicates
            ->map(function ($row) use ($columns) {
                $pairs = array_map(fn (string $c): string => $c.'='.var_export($row->{$c} ?? null, true), $columns);

                return '('.implode(', ', $pairs).') ×'.$row->occurrences;
            })
            ->implode('; ');

        throw new RuntimeException(
            "Cannot make {$table}.(".implode(', ', $columns).') unique: the same value '
            .'exists more than once within a single tenant, because it used to be unique '
            .'per branch instead. Merge or rename the duplicates, then re-run this '
            ."migration. Conflicts: {$rendered}"
        );
    }
};
