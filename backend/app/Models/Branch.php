<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Branch (a.k.a. warehouse). The table is named `warehouses` for legacy
 * compatibility (decision D3); the domain term everywhere is "Branch". A
 * tenant-owned root — see App\Models\Concerns\BelongsToTenant.
 */
class Branch extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $table = 'warehouses';

    protected $fillable = [
        'tenant_id',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
