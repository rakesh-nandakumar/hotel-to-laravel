<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoomType extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'name',
        'max_occupancy',
        'bed_config',
        'amenities',
        'weekday_rate',
        'weekend_rate',
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
            'weekday_rate' => 'integer',
            'weekend_rate' => 'integer',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function seasonalRates(): HasMany
    {
        return $this->hasMany(SeasonalRate::class);
    }
}
