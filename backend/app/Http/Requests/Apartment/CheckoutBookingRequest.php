<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_bookings.checkout') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paymentMethod = Rule::in([PaymentMethod::CASH, PaymentMethod::CARD, PaymentMethod::LANKAQR, PaymentMethod::BANK_TRANSFER]);

        return [
            'apply_late_surcharge' => ['nullable', 'boolean'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required', 'string', $paymentMethod],
            'payments.*.amount' => ['required', 'integer', 'min:1'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'refund_method' => ['nullable', 'string', $paymentMethod],
        ];
    }
}
