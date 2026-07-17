<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_packages.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_per_person_per_night' => ['sometimes', 'integer', 'min:0'],
            'meal_inclusions' => ['array'],
            'meal_inclusions.*' => ['string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
