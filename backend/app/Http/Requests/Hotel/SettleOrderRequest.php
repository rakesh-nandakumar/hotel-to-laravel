<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_orders.settle') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::PAYMENT_METHOD)],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
