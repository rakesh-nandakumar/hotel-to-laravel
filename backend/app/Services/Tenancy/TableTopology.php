<?php

namespace App\Services\Tenancy;

/**
 * Orders tables by their foreign-key dependencies. Shared by the test-instance
 * clone engine and the tenant data purge/restore engine, so both agree on what
 * "parents first" means.
 */
final class TableTopology
{
    /**
     * @param  array<string, list<string>>  $dependencies  table => tables it must come after
     * @return list<string> tables, parents first
     */
    public static function order(array $dependencies): array
    {
        $names = array_keys($dependencies);

        $deps = [];
        foreach ($dependencies as $name => $targets) {
            $deps[$name] = collect($targets)
                ->unique()
                ->reject(fn (string $target): bool => $target === $name || ! isset($dependencies[$target]))
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

                $unresolved = array_filter(
                    $deps[$name],
                    fn (string $dep): bool => ! isset($processed[$dep]),
                );

                if ($unresolved === []) {
                    $processed[$name] = true;
                    $order[] = $name;
                    $progress = true;
                }
            }

            if (! $progress) {
                // Cycle (users ↔ roles etc.): force the remaining tables into
                // input order; their blocking edges must be deferred by the caller.
                foreach ($names as $name) {
                    if (! isset($processed[$name])) {
                        $processed[$name] = true;
                        $order[] = $name;
                    }
                }
                break;
            }
        }

        return $order;
    }
}
