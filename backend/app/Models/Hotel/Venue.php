<?php

namespace App\Models\Hotel;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use BelongsToTenant, HasUserstamps, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'max_capacity',
        'facilities',
        'hourly_rate',
        'half_day_rate',
        'full_day_rate',
        'active',
        'created_by',
        'updated_by',
        // Enhanced package pricing fields
        'hall_type',
        'luxury_hall_charge',
        'basic_hall_charge',
        'per_plate_starting_price',
        'hall_only_per_person',
        'dj_included',
        'bar_charges_included',
        'byod_allowed',
        'use_package_pricing',
        'default_charge_defaults',
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
            'luxury_hall_charge' => 'integer',
            'basic_hall_charge' => 'integer',
            'per_plate_starting_price' => 'integer',
            'hall_only_per_person' => 'integer',
            'dj_included' => 'boolean',
            'bar_charges_included' => 'boolean',
            'byod_allowed' => 'boolean',
            'use_package_pricing' => 'boolean',
            'default_charge_defaults' => 'array',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VenueBooking::class);
    }

    /**
     * Get the appropriate hall charge based on hall type
     */
    public function getHallCharge(): int
    {
        return match ($this->hall_type) {
            'luxury' => $this->luxury_hall_charge,
            'basic' => $this->basic_hall_charge,
            default => $this->full_day_rate, // Fallback to legacy pricing
        };
    }

    /**
     * Check if this venue uses the new package pricing model
     */
    public function usesPackagePricing(): bool
    {
        return $this->use_package_pricing ?? false;
    }

    /**
     * Calculate hall-only booking cost
     */
    public function calculateHallOnlyCost(int $guestCount): int
    {
        return $this->hall_only_per_person * $guestCount;
    }

    /**
     * Calculate hall+food booking cost
     */
    public function calculateHallFoodCost(int $guestCount, ?int $perPlatePrice = null): int
    {
        $perPlate = $perPlatePrice ?? $this->per_plate_starting_price;
        $foodCost = $perPlate * $guestCount;
        $hallCharge = $this->getHallCharge();

        return $foodCost + $hallCharge;
    }
}
