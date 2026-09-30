<?php

use App\Services\Tenancy\DataPurge\PurgeGraph;
use App\Support\Lookups\LookupType;
use Illuminate\Support\Facades\Schema;

/*
| These tests guard config/tenancy-purge.php against the schema drifting away
| from it: a new tenant table, FK or lookup that the catalog does not know about
| fails here instead of silently escaping (or breaking) a purge.
*/

it('never lets a purge reach a table it must not touch', function () {
    expect(app(PurgeGraph::class)->violations())->toBe([]);
});

it('gives every purgeable tenant table a way to be purged', function () {
    $graph = app(PurgeGraph::class);

    $reachable = [];
    $queue = [];

    foreach (config('tenancy-purge.categories') as $category) {
        foreach ($category['roots'] as $table) {
            $reachable[$table] = true;
            $queue[] = $table;
        }
    }

    while ($queue !== []) {
        $table = array_shift($queue);
        $next = [];

        foreach ($graph->childrenOf($table) as $edge) {
            if ($edge['policy'] === PurgeGraph::CASCADE) {
                $next[] = $edge['child'];
            }
        }

        foreach ($graph->looseEdgesFrom($table) as $loose) {
            if ($loose['policy'] === PurgeGraph::CASCADE) {
                $next[] = $loose['child'];
            }
        }

        foreach ($graph->ownersOf($table) as $owner) {
            $next[] = $owner['parent'];
        }

        foreach ($next as $candidate) {
            if (! isset($reachable[$candidate])) {
                $reachable[$candidate] = true;
                $queue[] = $candidate;
            }
        }
    }

    // A tenant table that is neither reachable from a category nor protected
    // can never be purged — classify it in config/tenancy-purge.php.
    expect(array_values(array_diff($graph->scopeTables(), array_keys($reachable))))->toBe([]);
});

it('only ever protects tables that exist', function () {
    $existing = array_column(Schema::getTables(), 'name');

    foreach (config('tenancy-purge.protected') as $table) {
        expect($existing)->toContain($table);
    }
});

it('gives every purgeable table a primary id the engine can address', function () {
    $graph = app(PurgeGraph::class);

    foreach ($graph->scopeTables() as $table) {
        expect(array_keys($graph->columns($table)))->toContain('id');
    }
});

it('only uses existing tables as category roots, and never protected ones', function () {
    $graph = app(PurgeGraph::class);

    foreach (config('tenancy-purge.categories') as $key => $category) {
        expect(array_keys(config('tenancy-purge.modules')))->toContain($category['module']);

        foreach ($category['roots'] as $table) {
            expect($graph->isScope($table))->toBeTrue("{$key} root {$table} must be a purgeable tenant table");
        }
    }
});

it('points every category filter at a column that exists on each of its roots', function () {
    $graph = app(PurgeGraph::class);
    $lookupTypes = array_values((new ReflectionClass(LookupType::class))->getConstants());

    foreach (config('tenancy-purge.categories') as $key => $category) {
        foreach ($category['filters'] as $spec) {
            expect($spec['type'])->toBeIn(['date', 'month', 'lookup']);

            foreach ($category['roots'] as $table) {
                $column = is_array($spec['column']) ? ($spec['column'][$table] ?? null) : $spec['column'];

                expect($column)->not->toBeNull("{$key}.{$spec['key']} has no column for {$table}");
                expect(array_keys($graph->columns($table)))->toContain($column);
            }

            if ($spec['type'] === 'lookup') {
                expect($lookupTypes)->toContain($spec['lookup']);
            }
        }
    }
});

it('refers only to real foreign keys in its edge overrides and owner rules', function () {
    $graph = app(PurgeGraph::class);

    foreach (array_keys(config('tenancy-purge.edge_overrides')) as $key) {
        [$child, $column] = explode('.', $key);

        expect($graph->policyFor($child, $column))->not->toBeNull("edge override {$key} is not an FK between purgeable tables");
    }

    foreach (config('tenancy-purge.owners') as $owner) {
        expect($graph->ownersOf($owner['child']))->not->toBe([], "owner rule {$owner['child']}.{$owner['column']} matches no FK");
    }
});

it('refers only to real columns in its polymorphic and document-number rules', function () {
    $graph = app(PurgeGraph::class);

    foreach (config('tenancy-purge.loose_edges') as $loose) {
        $columns = array_keys($graph->columns($loose['child']));

        expect($columns)->toContain($loose['type_column'])->toContain($loose['id_column']);

        foreach ($loose['targets'] as $table) {
            expect($graph->isScope($table))->toBeTrue();
        }
    }

    foreach (config('tenancy-purge.document_numbers') as $series) {
        expect(array_keys($graph->columns($series['table'])))->toContain($series['column']);
    }
});

it('can always delete children before the parents that own them', function () {
    $graph = app(PurgeGraph::class);
    $position = array_flip($graph->deletionOrder());

    foreach ($graph->edges() as $edge) {
        if ($edge['policy'] === PurgeGraph::CASCADE && $edge['child'] !== $edge['parent']) {
            expect($position[$edge['child']])->toBeLessThan($position[$edge['parent']], "{$edge['child']} must be deleted before {$edge['parent']}");
        }
    }
});

it('can always insert the parents an ownership or required link needs first', function () {
    $graph = app(PurgeGraph::class);
    $position = array_flip($graph->insertOrder());

    foreach ($graph->edges() as $edge) {
        if (($edge['policy'] === PurgeGraph::CASCADE || ! $edge['nullable']) && $edge['child'] !== $edge['parent']) {
            expect($position[$edge['parent']])->toBeLessThan($position[$edge['child']], "{$edge['parent']} must be restored before {$edge['child']}");
        }
    }
});
