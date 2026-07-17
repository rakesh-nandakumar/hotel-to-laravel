<?php

namespace App\Models;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Generic lookup/reference table replacing what would otherwise be a DB enum
 * (see coding_principles.md §2). One physical table, discriminated by `type`
 * (e.g. "reservation_status"); business code always compares on the immutable
 * `code`, never the surrogate `id` (coding_principles.md §3).
 */
class Lookup extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'type',
        'code',
        'name',
        'color',
        'sort_order',
        'is_active',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<Lookup>  $query
     */ ..
    public function scopeType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @param  Builder<Lookup>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Resolve a lookup row's id from its (type, code) pair, cached for the
     * process lifetime of the request plus a persistent cache layer — lookup
     * rows change rarely, so this avoids a query on every write that needs a
     * status/classification FK.
     */
    public static function id(string $type, string $code): int
    {
        return Cache::rememberForever(
            "lookup:{$type}:{$code}",
            fn () => static::query()->type($type)->where('code', $code)->value('id')
                ?? throw new \RuntimeException("Unknown lookup [{$type}.{$code}] — has it been seeded?"),
        );
    }

    /**
     * Every active row for a type, ordered for select/dropdown display.
     *
     * @return \Illuminate\Support\Collection<int, Lookup>
     */
    public static function options(string $type): \Illuminate\Support\Collection
    {
        return static::query()->type($type)->active()->orderBy('sort_order')->get();
    }

    public static function flushCache(string $type, string $code): void
    {
        Cache::forget("lookup:{$type}:{$code}");
    }
}
