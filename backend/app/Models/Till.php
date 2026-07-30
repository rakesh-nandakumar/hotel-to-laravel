<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical/named cash drawer at a branch (e.g. "Front Desk Till",
 * "Restaurant Till"). Shared across Hotel, Restaurant, and Apartments — the
 * single source of truth for physical cash, regardless of which module the
 * cash came from (see TillMovement).
 */
class Till extends Model
{
    use BelongsToBranch, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TillSession::class);
    }

    /**
     * @param  Builder<Till>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
