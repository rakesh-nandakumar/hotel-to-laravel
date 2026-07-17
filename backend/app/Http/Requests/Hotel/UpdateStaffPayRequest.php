<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_payroll.manage_pay') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_salary' => ['sometimes', 'integer', 'min:0'],
            'ot_hourly_rate' => ['sometimes', 'integer', 'min:0'],
            'monthly_allowance' => ['sometimes', 'integer', 'min:0'],
            'epf_enabled' => ['sometimes', 'boolean'],
            'epf_number' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
