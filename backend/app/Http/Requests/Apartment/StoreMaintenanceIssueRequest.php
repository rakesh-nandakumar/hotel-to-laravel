<?php

namespace App\Http\Requests\Apartment;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_maintenance.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', 'exists:apartment_units,id'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'take_unit_out_of_service' => ['nullable', 'boolean'],
        ];
    }
}
