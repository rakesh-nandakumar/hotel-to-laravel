<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenueExtraCharge extends Model
{
    use BelongsToTenant, HasUserstamps;

    protected $fillable = [
        'tenant_id',
        'venue_booking_id',
        'description',
        'amount',
        'charge_type',
        'is_percentage',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_percentage' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function venueBooking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Common charge types for venue bookings based on user's spreadsheet
     */
    public const CHARGE_TYPES = [
        'ac' => 'AC',
        'table' => 'Table',
        'cleaning_staff' => 'Cleaning Staff',
        'service_supply' => 'Service Supply',
        'water' => 'Water',
        'light' => 'Light',
        'dj' => 'DJ',
        'water_cleaning' => 'Water (Cleaning)',
        'extra_kitchen' => 'Extra Kitchen',
        'other' => 'Other',
        'service_charge' => 'Service Charge',
    ];
}
