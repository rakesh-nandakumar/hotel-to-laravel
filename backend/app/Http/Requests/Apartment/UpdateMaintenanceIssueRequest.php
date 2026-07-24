<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaintenanceIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_maintenance.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::MAINTENANCE_STATUS)],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
            'return_unit_to_service' => ['nullable', 'boolean'],
        ];
    }
}
