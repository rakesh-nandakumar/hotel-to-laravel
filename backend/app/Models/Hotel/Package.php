<?php

namespace App\Models\Hotel;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_per_person_per_night',
        'meal_inclusions',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'price_per_person_per_night' => 'integer',
            'meal_inclusions' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Package>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
