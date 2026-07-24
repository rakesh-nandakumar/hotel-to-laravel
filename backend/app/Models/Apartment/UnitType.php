<?php

namespace App\Models\Apartment;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitType extends Model
{
    use HasUserstamps, SoftDeletes;

    protected $table = 'apartment_unit_types';

    protected $fillable = [
        'name',
        'max_occupancy',
        'bedrooms',
        'bathrooms',
        'size_sqft',
        'amenities',
        'nightly_rate',
        'weekly_rate',
        'monthly_rate',
        'min_nights',
        'cleaning_fee',
        'extra_guest_fee',
        'item_checklist',
        'cleaning_checklist',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'item_checklist' => 'array',
            'cleaning_checklist' => 'array',
            'max_occupancy' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'size_sqft' => 'integer',
            'nightly_rate' => 'integer',
            'weekly_rate' => 'integer',
            'monthly_rate' => 'integer',
            'min_nights' => 'integer',
            'cleaning_fee' => 'integer',
            'extra_guest_fee' => 'integer',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function seasonalRates(): HasMany
    {
        return $this->hasMany(SeasonalRate::class);
    }
}
