<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_bookings.cancel') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'refund_method' => ['nullable', 'string', Rule::in([
                PaymentMethod::CASH, PaymentMethod::CARD, PaymentMethod::LANKAQR, PaymentMethod::BANK_TRANSFER,
            ])],
        ];
    }
}
