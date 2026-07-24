<?php

namespace App\Http\Requests\Apartment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_unit_types.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('apartment_unit_types', 'name')],
            'max_occupancy' => ['required', 'integer', 'min:1'],
            'bedrooms' => ['required', 'integer', 'min:0'],
            'bathrooms' => ['required', 'integer', 'min:0'],
            'size_sqft' => ['nullable', 'integer', 'min:0'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'nightly_rate' => ['nullable', 'integer', 'min:0'],
            'weekly_rate' => ['nullable', 'integer', 'min:0'],
            'monthly_rate' => ['nullable', 'integer', 'min:0'],
            'min_nights' => ['required', 'integer', 'min:1'],
            'cleaning_fee' => ['required', 'integer', 'min:0'],
            'extra_guest_fee' => ['required', 'integer', 'min:0'],
            'item_checklist' => ['array'],
            'item_checklist.*' => ['string', 'max:255'],
            'cleaning_checklist' => ['array'],
            'cleaning_checklist.*' => ['string', 'max:255'],
        ];
    }
}
