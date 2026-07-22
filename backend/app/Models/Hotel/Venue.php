<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;

use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'name',
        'max_capacity',
        'facilities',
        'hourly_rate',
        'half_day_rate',
        'full_day_rate',
        'active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'max_capacity' => 'integer',
            'facilities' => 'array',
            'hourly_rate' => 'integer',
            'half_day_rate' => 'integer',
            'full_day_rate' => 'integer',
            'active' => 'boolean',
        ];
    }



    public function bookings(): HasMany
    {
        return $this->hasMany(VenueBooking::class);
    }
}
