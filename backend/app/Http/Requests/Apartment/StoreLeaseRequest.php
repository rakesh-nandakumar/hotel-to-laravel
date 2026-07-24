<?php

namespace App\Http\Requests\Apartment;

use App\Support\Lookups\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('apartment_leases.create') ?? false;
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
            'new_customer.is_company' => ['nullable', 'boolean'],
            'new_customer.company_name' => ['nullable', 'string', 'max:150'],

            'unit_id' => ['required', 'integer', 'exists:apartment_units,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'monthly_rent' => ['required', 'integer', 'min:1'],
            'rent_due_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'security_deposit' => ['nullable', 'integer', 'min:0'],
            'notice_period_days' => ['nullable', 'integer', 'min:0'],
            'auto_renew' => ['nullable', 'boolean'],
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
