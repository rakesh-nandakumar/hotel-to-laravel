<?php

namespace App\Models;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Branch (a.k.a. warehouse). The table is named `warehouses` for legacy
 * compatibility (decision D3); the domain term everywhere is "Branch".
 */
class Branch extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $table = 'warehouses';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'city',
        'country',
        'is_active',
        'manager_user_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Branch>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
