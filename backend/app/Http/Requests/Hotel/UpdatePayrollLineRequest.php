<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_payroll.adjust_line') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ot_hours' => ['sometimes', 'numeric', 'min:0'],
            'bonus' => ['sometimes', 'integer', 'min:0'],
            'unpaid_leave_deduction' => ['sometimes', 'integer', 'min:0'],
            'loan' => ['sometimes', 'integer', 'min:0'],
            'advance' => ['sometimes', 'integer', 'min:0'],
            'other_deduction' => ['sometimes', 'integer', 'min:0'],
            'other_deduction_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
