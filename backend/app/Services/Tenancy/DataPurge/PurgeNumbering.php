<?php

namespace App\Services\Tenancy\DataPurge;

use App\Services\DocumentNumberService;
use Illuminate\Support\Facades\DB;

/**
 * Keeps document numbering collision-free across a purge.
 *
 * Numbers are derived from the highest surviving row, so after a purge the
 * series simply restarts from whatever is left (INV-0001 after a full wipe).
 * With "continue numbering" the highest deleted number of every series is
 * remembered as a floor, so it is never issued again.
 */
final class PurgeNumbering
{
    private const CHUNK = 1000;

    /**
     * Highest number per series among the rows about to be deleted. Read it
     * BEFORE deleting.
     *
     * @return array<string, int> series prefix => highest deleted number
     */
    public function collect(PurgePlan $plan): array
    {
        $highest = [];

        foreach (config('tenancy-purge.document_numbers', []) as $series) {
            if (($series['kind'] ?? 'prefixed') !== 'prefixed' || ($series['floor'] ?? true) === false) {
                continue;
            }

            foreach (array_chunk($plan->ids[$series['table']] ?? [], self::CHUNK) as $chunk) {
                $codes = DB::table($series['table'])->whereIn('id', $chunk)->whereNotNull($series['column'])->pluck($series['column']);

                foreach ($codes as $code) {
                    $parsed = DocumentNumberService::parse((string) $code);

                    if ($parsed !== null) {
                        $highest[$parsed['prefix']] = max($highest[$parsed['prefix']] ?? 0, $parsed['number']);
                    }
                }
            }
        }

        return $highest;
    }

    /**
     * @param  array<string, int>  $highest  from {@see collect()}
     */
    public function apply(int $tenantId, array $highest, bool $continue): void
    {
        if ($highest === []) {
            return;
        }

        if (! $continue) {
            DB::table('tenant_document_number_floors')
                ->where('tenant_id', $tenantId)
                ->whereIn('prefix', array_keys($highest))
                ->delete();

            return;
        }

        foreach ($highest as $prefix => $number) {
            $series = fn () => DB::table('tenant_document_number_floors')
                ->where('tenant_id', $tenantId)
                ->where('prefix', (string) $prefix);

            $existing = $series()->value('last_number');

            if ($existing === null) {
                DB::table('tenant_document_number_floors')->insert([
                    'tenant_id' => $tenantId,
                    'prefix' => (string) $prefix,
                    'last_number' => $number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $series()->update(['last_number' => max((int) $existing, $number), 'updated_at' => now()]);
            }
        }
    }
}
