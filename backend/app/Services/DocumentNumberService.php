<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Sequential human-friendly document codes (RSV-0007, GRP-0002, INV-2026-0012...).
 * Ported from the Node app's lib/codes.ts, which was a naive max+1 with a doc
 * comment claiming collision retry that didn't actually exist — a real
 * concurrency gap. This version closes it with lockForUpdate() so two
 * concurrent requests can't be handed the same number.
 *
 * Two rules keep the next code collision-free:
 *  - soft-deleted rows still count (their unique index entries remain), and
 *  - a tenant "floor" (tenant_document_number_floors, written by a data purge
 *    run with "continue numbering") is never reissued.
 */
class DocumentNumberService
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function next(string $modelClass, string $column, string $prefix, int $pad = 4): string
    {
        return DB::transaction(function () use ($modelClass, $column, $prefix, $pad) {
            $query = $modelClass::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                $query->withTrashed();
            }

            $last = $query
                ->where($column, 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc($column)
                ->value($column);

            $n = $last ? ((int) substr((string) $last, strrpos((string) $last, '-') + 1)) + 1 : 1;
            $n = max($n, $this->floor($prefix) + 1);

            return $prefix.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Splits "INV-2026-0012" into its series prefix, running number and width.
     *
     * @return array{prefix: string, number: int, width: int}|null null when the code has no numeric tail
     */
    public static function parse(string $code): ?array
    {
        $dash = strrpos($code, '-');

        if ($dash === false) {
            return null;
        }

        $tail = substr($code, $dash + 1);

        if ($tail === '' || ! ctype_digit($tail)) {
            return null;
        }

        return ['prefix' => substr($code, 0, $dash + 1), 'number' => (int) $tail, 'width' => strlen($tail)];
    }

    private function floor(string $prefix): int
    {
        $tenantId = app(CurrentContext::class)->tenantId();

        if ($tenantId === null) {
            return 0;
        }

        return (int) DB::table('tenant_document_number_floors')
            ->where('tenant_id', $tenantId)
            ->where('prefix', $prefix)
            ->value('last_number');
    }
}
