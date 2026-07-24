<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\ApartmentUtilityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUtilityReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_leases.utility_reading') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'utility_type' => ['required', 'string', Rule::in([
                ApartmentUtilityType::ELECTRICITY, ApartmentUtilityType::WATER, ApartmentUtilityType::GAS,
            ])],
            'period_month' => ['required', 'date'],
            'previous_reading' => ['required', 'numeric', 'min:0'],
            'current_reading' => ['required', 'numeric', 'min:0'],
            'rate_per_unit' => ['required', 'integer', 'min:0'],
        ];
    }
}
