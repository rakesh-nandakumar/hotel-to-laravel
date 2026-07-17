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
        ];
    }
}
