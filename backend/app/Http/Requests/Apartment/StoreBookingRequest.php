<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\ApartmentBookingChannel;
use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_bookings.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required_without:new_customer', 'nullable', 'integer', 'exists:apartment_customers,id'],
            'new_customer' => ['required_without:customer_id', 'nullable', 'array'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:150'],
            'new_customer.phone' => ['nullable', 'string', 'max:30'],
            'new_customer.email' => ['nullable', 'string', 'email', 'max:255'],
            'new_customer.id_number' => ['nullable', 'string', 'max:50'],
            'new_customer.nationality' => ['nullable', 'string', 'max:100'],

            'channel' => ['required', 'string', Rule::in([
                ApartmentBookingChannel::DIRECT, ApartmentBookingChannel::AIRBNB, ApartmentBookingChannel::BOOKING_COM,
                ApartmentBookingChannel::AGENT, ApartmentBookingChannel::WALK_IN,
            ])],
            'unit_id' => ['required', 'integer', 'exists:apartment_units,id'],
            'check_in' => ['required', 'date'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

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
