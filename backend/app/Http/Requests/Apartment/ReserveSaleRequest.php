<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReserveSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_sales.reserve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reserved_until' => ['nullable', 'date', 'after:today'],

            'deposit_payment' => ['nullable', 'array'],
            'deposit_payment.method' => [
                'required_with:deposit_payment', 'string',
                Rule::in([PaymentMethod::CASH, PaymentMethod::CARD, PaymentMethod::LANKAQR, PaymentMethod::BANK_TRANSFER]),
            ],
            'deposit_payment.amount' => ['required_with:deposit_payment', 'integer', 'min:1'],
            'deposit_payment.reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
