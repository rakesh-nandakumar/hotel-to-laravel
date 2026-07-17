<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyOrderDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.discount') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(['PCT', 'FIXED'])],
            'value' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
