<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\ApartmentListingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_units.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_no' => ['required', 'string', 'max:20', Rule::unique('apartment_units', 'unit_no')],
            'property_id' => ['nullable', 'integer', 'exists:apartment_properties,id'],
            'unit_type_id' => ['required', 'integer', 'exists:apartment_unit_types,id'],
            'floor' => ['nullable', 'string', 'max:50'],
            'view' => ['nullable', 'string', 'max:50'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'listing_type' => ['required', 'string', Rule::in([ApartmentListingType::RENTAL, ApartmentListingType::SALE])],
            'sale_price' => ['nullable', 'integer', 'min:0', 'required_if:listing_type,'.ApartmentListingType::SALE],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
