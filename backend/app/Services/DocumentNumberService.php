<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Sequential human-friendly document codes (RSV-0007, GRP-0002, INV-2026-0012...).
 * Ported from the Node app's lib/codes.ts, which was a naive max+1 with a doc
 * comment claiming collision retry that didn't actually exist — a real
 * concurrency gap. This version closes it with lockForUpdate() so two
 * concurrent requests can't be handed the same number.
 */
class DocumentNumberService
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    public function next(string $modelClass, string $column, string $prefix, int $pad = 4): string
    {
        return DB::transaction(function () use ($modelClass, $column, $prefix, $pad) {
            $last = $modelClass::query()
                ->where($column, 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc($column)
                ->value($column);

            $n = $last ? ((int) substr((string) $last, strrpos((string) $last, '-') + 1)) + 1 : 1;

            return $prefix.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }
}
