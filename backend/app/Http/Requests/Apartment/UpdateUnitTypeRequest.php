<?php

namespace App\Http\Requests\Apartment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_unit_types.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unitType = $this->route('unitType');

        return [
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('apartment_unit_types', 'name')->ignore($unitType)],
            'max_occupancy' => ['sometimes', 'integer', 'min:1'],
            'bedrooms' => ['sometimes', 'integer', 'min:0'],
            'bathrooms' => ['sometimes', 'integer', 'min:0'],
            'size_sqft' => ['nullable', 'integer', 'min:0'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'nightly_rate' => ['nullable', 'integer', 'min:0'],
            'weekly_rate' => ['nullable', 'integer', 'min:0'],
            'monthly_rate' => ['nullable', 'integer', 'min:0'],
            'min_nights' => ['sometimes', 'integer', 'min:1'],
            'cleaning_fee' => ['sometimes', 'integer', 'min:0'],
            'extra_guest_fee' => ['sometimes', 'integer', 'min:0'],
            'item_checklist' => ['array'],
            'item_checklist.*' => ['string', 'max:255'],
            'cleaning_checklist' => ['array'],
            'cleaning_checklist.*' => ['string', 'max:255'],
        ];
    }
}
