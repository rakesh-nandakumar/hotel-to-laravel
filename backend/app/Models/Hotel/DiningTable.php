<?php

namespace App\Models\Hotel;

use App\Models\Lookup;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiningTable extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'table_no',
        'dining_area_id',
        'capacity',
        'table_status_id',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(DiningArea::class, 'dining_area_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'table_status_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'dining_table_id');
    }

    /**
     * @param  Builder<DiningTable>  $query
     */
    public function scopeStatusCode(Builder $query, string $code): void
    {
        $query->whereHas('status', fn (Builder $q) => $q->where('code', $code));
    }
}
