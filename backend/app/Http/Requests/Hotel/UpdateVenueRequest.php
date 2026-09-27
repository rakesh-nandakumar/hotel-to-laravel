<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_venues.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'max_capacity' => ['sometimes', 'integer', 'min:1'],
            'facilities' => ['sometimes', 'array'],
            'facilities.*' => ['string', 'max:120'],
            'hourly_rate' => ['sometimes', 'integer', 'min:0'],
            'half_day_rate' => ['sometimes', 'integer', 'min:0'],
            'full_day_rate' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            // New package pricing fields
            'hall_type' => ['sometimes', 'nullable', 'in:luxury,basic'],
            'luxury_hall_charge' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'basic_hall_charge' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'per_plate_starting_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'hall_only_per_person' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'dj_included' => ['sometimes', 'nullable', 'boolean'],
            'bar_charges_included' => ['sometimes', 'nullable', 'boolean'],
            'byod_allowed' => ['sometimes', 'nullable', 'boolean'],
            'use_package_pricing' => ['sometimes', 'boolean'],
        ];
    }
}
