<?php

namespace App\Models\Apartment;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $table = 'apartment_properties';

    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'phone',
        'email',
        'notes',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @param  Builder<Property>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
