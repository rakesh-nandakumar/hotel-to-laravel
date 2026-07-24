<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\ApartmentListingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_units.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unit = $this->route('unit');

        return [
            'unit_no' => ['sometimes', 'string', 'max:20', Rule::unique('apartment_units', 'unit_no')->ignore($unit)],
            'property_id' => ['nullable', 'integer', 'exists:apartment_properties,id'],
            'unit_type_id' => ['sometimes', 'integer', 'exists:apartment_unit_types,id'],
            'floor' => ['nullable', 'string', 'max:50'],
            'view' => ['nullable', 'string', 'max:50'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'listing_type' => ['sometimes', 'string', Rule::in([ApartmentListingType::RENTAL, ApartmentListingType::SALE])],
            'sale_price' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
